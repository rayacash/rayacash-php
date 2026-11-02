<?php

declare(strict_types=1);

namespace RayaCash\HttpClient;

/**
 * El cliente HTTP de serie, sobre ext-curl. Reusa la conexión entre requests (curl_reset nunca
 * la cierra) y habla HTTP/1.1, como httpx y undici.
 *
 * Para un proxy o un certificado propio, pasale opciones de curl:
 * `new CurlClient([CURLOPT_PROXY => 'http://proxy:3128', CURLOPT_CAINFO => '/etc/ssl/ca.pem'])`.
 * Las que maneja el SDK (URL, método, headers, body, timeouts, redirects) no se aceptan, y
 * tampoco apagar la verificación TLS ni CURLOPT_VERBOSE, que muestra la clave. Tus opciones
 * pueden llevar contraseñas: no aparecen en var_dump, var_export ni (array), y no se serializan.
 */
final class CurlClient implements HttpClientInterface
{
    // Timeout de conexión o de la transferencia completa.
    private const TIMEOUT_ERROR = 28;
    // Proxy, DNS, conexión, HTTP/2, body cortado, TLS, envío/recepción y certificado: se
    // reintentan, como los errores de red de httpx y undici. El resto (URL mal armada,
    // protocolo, callback…) no: daría lo mismo.
    private const RETRYABLE_ERRORS = [5, 6, 7, 8, 16, 18, 35, 52, 55, 56, 60, 92, 95, 97];
    private const MANAGED_OPTIONS = [
        'CURLOPT_URL', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_POST', 'CURLOPT_HTTPGET',
        'CURLOPT_NOBODY', 'CURLOPT_PUT', 'CURLOPT_UPLOAD', 'CURLOPT_POSTFIELDS',
        'CURLOPT_READFUNCTION', 'CURLOPT_INFILE', 'CURLOPT_INFILESIZE', 'CURLOPT_HTTPHEADER',
        'CURLOPT_HEADER', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_HEADERFUNCTION',
        'CURLOPT_WRITEFUNCTION', 'CURLOPT_FILE', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS',
        'CURLOPT_TIMEOUT', 'CURLOPT_TIMEOUT_MS', 'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_CONNECTTIMEOUT_MS', 'CURLOPT_PROTOCOLS', 'CURLOPT_PROTOCOLS_STR',
        'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS_STR', 'CURLOPT_NOSIGNAL',
        'CURLOPT_USERAGENT', 'CURLOPT_USERPWD', 'CURLOPT_HTTPAUTH',
        'CURLOPT_SUPPRESS_CONNECT_HEADERS', 'CURLOPT_HEADEROPT',
    ];
    // Escriben en STDERR (o le pasan a tu callback) los headers de cada request, con el
    // Authorization: la clave entera, en el log.
    private const DEBUG_OPTIONS = ['CURLOPT_VERBOSE', 'CURLOPT_DEBUGFUNCTION'];
    // CURL_ERROR_SIZE, que PHP no define: curl corta su mensaje de error en 255 bytes (más el
    // NUL del final).
    private const ERROR_SIZE = 256;

    /**
     * @var \WeakMap<\stdClass, array<int, mixed>>|null Las opciones de cada cliente, fuera del
     *     objeto como la clave en Internal\Secret: pueden llevar la contraseña del proxy o la del
     *     certificado.
     */
    private static ?\WeakMap $optionsOf = null;

    /** Dónde están tus opciones en $optionsOf: un clon comparte las mismas. */
    private readonly \stdClass $token;

    private ?\CurlHandle $handle = null;

    /** @param array<int, mixed> $curlOptions Opciones CURLOPT_* extra (proxy, CA, interfaz…). */
    public function __construct(#[\SensitiveParameter] array $curlOptions = [])
    {
        $managed = self::constants(self::MANAGED_OPTIONS);
        $debug = self::constants(self::DEBUG_OPTIONS);
        foreach ($curlOptions as $option => $value) {
            if (!is_int($option)) {
                throw new \TypeError(
                    'Las opciones de CurlClient son constantes CURLOPT_*, como CURLOPT_PROXY, no '
                    . "\"{$option}\".",
                );
            }
            if (isset($managed[$option])) {
                throw new \ValueError(
                    "CurlClient no acepta {$managed[$option]}: el SDK la maneja (URL, método, "
                    . 'headers, body, timeouts y redirects).',
                );
            }
            if (isset($debug[$option])) {
                throw new \ValueError(
                    "CurlClient no acepta {$debug[$option]}: muestra los headers de cada request, "
                    . 'con la clave entera en Authorization. Para ver las requests, usá '
                    . 'RAYA_LOG=debug.',
                );
            }
            if ($option === CURLOPT_SSL_VERIFYHOST && ($value === true || $value === '2')) {
                // true y '2' (de un .env) también verifican: quedan como 2. curl_setopt()
                // convierte true en 1, que libcurl ya no acepta (avisa con un notice y usa 2).
                $curlOptions[$option] = $value = 2;
            }
            $tlsOff = ($option === CURLOPT_SSL_VERIFYPEER && !$value)
                || ($option === CURLOPT_SSL_VERIFYHOST && $value !== 2);
            if ($tlsOff) {
                throw new \ValueError(
                    'CurlClient no acepta desactivar la verificación TLS: si usás un certificado '
                    . 'propio, pasalo con CURLOPT_CAINFO.',
                );
            }
        }
        $this->token = new \stdClass();
        self::optionsOf()[$this->token] = $curlOptions;
        if ($curlOptions !== []) {
            // Una opción inválida falla acá, al armar el cliente, y no en la primera request.
            $probe = self::newHandle();
            foreach ($curlOptions as $option => $value) {
                if (!curl_setopt($probe, $option, $value)) {
                    throw new \ValueError(
                        'CurlClient no pudo aplicar ' . self::optionName($option)
                        . ': revisá su valor.',
                    );
                }
            }
        }
    }

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        $handle = $this->handle ??= self::newHandle();
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        // Sin «Expect: 100-continue», que libcurl agrega a los POST grandes.
        $headers[] = 'Expect:';
        // Una línea por header de cada respuesta: un «HTTP/…» empieza otra (100 Continue, el
        // CONNECT de un proxy) y descarta los headers anteriores.
        $lines = [];
        $collect = static function (\CurlHandle $handle, string $line) use (&$lines): int {
            $header = rtrim($line, "\r\n");
            if (\str_starts_with($header, 'HTTP/')) {
                $lines = [];
            } elseif ($header !== '') {
                $lines[] = $header;
            }
            return strlen($line);
        };
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ceil($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($request->connectTimeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Los headers de la request nunca van a un log: el Authorization lleva la clave (y
            // CURLINFO_HEADER_OUT también prende el modo verbose).
            CURLOPT_VERBOSE => false,
            CURLOPT_HEADERFUNCTION => $collect,
        ];
        if ($request->method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            if ($request->method !== 'POST') {
                $options[CURLOPT_CUSTOMREQUEST] = $request->method;
            }
            // Sin body: Content-Length 0 y sin el Content-Type de formulario de libcurl.
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
            if ($request->body === null) {
                $headers[] = 'Content-Type:';
            }
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        if (\defined('CURLOPT_PROTOCOLS_STR')) {
            $options[(int) \constant('CURLOPT_PROTOCOLS_STR')] = 'https,http';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        if (\defined('CURLOPT_SUPPRESS_CONNECT_HEADERS')) {
            $options[(int) \constant('CURLOPT_SUPPRESS_CONNECT_HEADERS')] = true;
        }
        if (\defined('CURLOPT_HEADEROPT')) {
            // Los headers van solo a la API, nunca al CONNECT de un proxy, que los vería sin TLS.
            $options[(int) \constant('CURLOPT_HEADEROPT')] = \constant('CURLHEADER_SEPARATE');
        }

        curl_reset($handle);
        // Valores por defecto que tus opciones pueden cambiar; las del SDK van al final.
        curl_setopt_array($handle, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '',
        ]);
        curl_setopt_array($handle, self::optionsOf()[$this->token] ?? []);
        foreach ($options as $option => $value) {
            if (!curl_setopt($handle, $option, $value)) {
                throw TransportException::network(
                    'curl: no se pudo aplicar ' . self::optionName($option),
                    false,
                );
            }
        }
        $body = curl_exec($handle);
        if (!is_string($body) || curl_errno($handle) !== 0) {
            $errno = curl_errno($handle);
            if ($errno === self::TIMEOUT_ERROR) {
                throw TransportException::timeout(self::detail($handle));
            }
            throw TransportException::network(
                self::detail($handle),
                \in_array($errno, self::RETRYABLE_ERRORS, true),
            );
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        return new Response($status, self::headers($lines), $body);
    }

    /** Suelta la conexión (para workers largos). El cliente sigue usable: abre otra. */
    public function close(): void
    {
        $this->handle = null;
    }

    /**
     * Lo que muestran var_dump y print_r: los nombres de tus opciones, sin sus valores.
     *
     * @return array{options: list<string>}
     */
    public function __debugInfo(): array
    {
        $names = [];
        foreach (array_keys(self::optionsOf()[$this->token] ?? []) as $option) {
            $names[] = self::optionName($option);
        }
        return ['options' => $names];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException(
            'Un CurlClient no se puede serializar (sus opciones pueden llevar contraseñas): creá '
            . 'uno en cada proceso.',
        );
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException(
            'Un CurlClient no se puede serializar (sus opciones pueden llevar contraseñas): creá '
            . 'uno en cada proceso.',
        );
    }

    private static function newHandle(): \CurlHandle
    {
        $handle = curl_init();
        if ($handle === false) {
            throw TransportException::network('curl: no se pudo crear el handle', false);
        }
        return $handle;
    }

    private static function detail(\CurlHandle $handle): string
    {
        $errno = curl_errno($handle);
        $message = curl_error($handle);
        if ($message === '') {
            $message = (string) curl_strerror($errno);
        }
        // curl cita entera la URL del proxy que no puede usar (de CURLOPT_PROXY o de
        // https_proxy): el usuario y la contraseña, hasta la última @, quedan como «…». Si curl
        // cortó el mensaje en su tope, la @ antes del host pudo no llegar (un usuario y una
        // contraseña largos, como un token), y la última que queda puede ser una de la
        // contraseña: entonces queda «…» todo lo que sigue al esquema (o a la comilla, si la URL
        // no tiene esquema).
        $cut = strlen($message) >= self::ERROR_SIZE - 1;
        $message = (string) \preg_replace(
            $cut ? '~(\'(?:[a-z][a-z0-9+.-]*://)?).*\z~is' : '~(\'(?:[a-z][a-z0-9+.-]*://)?).*@~is',
            $cut ? '${1}…' : '${1}…@',
            $message,
        );
        return "curl {$errno}: {$message}";
    }

    /** @param list<string> $lines */
    private static function headers(array $lines): Headers
    {
        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if ($colon !== false && $colon > 0) {
                $headers[\substr($line, 0, $colon)][] = \trim(\substr($line, $colon + 1), " \t");
            }
        }
        return new Headers($headers);
    }

    private static function optionName(int $option): string
    {
        $constants = get_defined_constants(true)['curl'] ?? [];
        foreach ($constants as $name => $value) {
            if ($value === $option && \str_starts_with($name, 'CURLOPT_')) {
                return $name;
            }
        }
        return "la opción {$option}";
    }

    /**
     * El valor de cada constante de curl de la lista que exista en este PHP, con su nombre.
     *
     * @param list<string> $names
     * @return array<int, string>
     */
    private static function constants(array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            $value = \defined($name) ? \constant($name) : null;
            if (is_int($value)) {
                $found[$value] = $name;
            }
        }
        return $found;
    }

    /** @return \WeakMap<\stdClass, array<int, mixed>> */
    private static function optionsOf(): \WeakMap
    {
        return self::$optionsOf ??= new \WeakMap();
    }
}
