<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\Exception\AuthenticationException;

/**
 * @internal Lo que se rechaza antes de mandar nada porque nunca puede andar: la clave, la
 * api_base, el timeout, los reintentos y el app_info. El resto (formatos, rangos) lo valida la
 * API: no se duplican reglas.
 */
final class Validation
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
    // curl recibe el timeout en milisegundos en un long, que en Windows es de 32 bits.
    private const MAX_TIMEOUT = 2147483;
    private const APP_INFO_FIELDS = ['name', 'version', 'url'];

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** La clave secreta: el formato lo valida la API; acá, lo que nunca puede andar. */
    public static function apiKey(#[\SensitiveParameter] mixed $key): string
    {
        if (!is_string($key)) {
            throw new \TypeError(
                'La clave secreta tiene que ser un string, no ' . get_debug_type($key) . '.',
            );
        }
        if (Text::isBlank($key)) {
            throw new AuthenticationException(
                "Falta la clave secreta: pasala a new RayaClient('raya_sk_live_…'), configurala "
                . 'con RayaCash::setApiKey() o definí la variable de entorno RAYA_SECRET_KEY.',
                errorCode: 'API_KEY_MISSING',
            );
        }
        if (\str_starts_with($key, 'raya_pk_')) {
            throw new AuthenticationException(
                'Usaste la clave pública (raya_pk_…). La API se usa con la clave secreta '
                . '(raya_sk_live_…), solo desde tu servidor.',
                errorCode: 'API_KEY_PUBLISHABLE_NOT_ALLOWED',
            );
        }
        if (\preg_match('/\A[\x21-\x7e]+\z/', $key) !== 1) {
            throw new AuthenticationException(
                'La clave secreta tiene espacios o caracteres inválidos: copiala de nuevo desde '
                . 'Developers › API Keys.',
                errorCode: 'API_KEY_INVALID',
            );
        }
        return $key;
    }

    /**
     * Una URL https:// (o http://localhost) sin query ni credenciales, sin la barra final ni el ?
     * o el # de una query o un fragmento vacíos.
     */
    public static function apiBase(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \TypeError(
                'api_base tiene que ser un string, no ' . get_debug_type($value) . '.',
            );
        }
        $url = Text::strip($value) ?? '';
        $parts = \preg_match('/\A[\x21-\x7e]+\z/', $url) === 1 ? parse_url($url) : false;
        if ($parts === false || !self::isAllowedBase($parts)) {
            throw new \ValueError(
                'api_base tiene que ser una URL https:// sin query ni credenciales (o '
                . 'http://localhost para desarrollo local).',
            );
        }
        // La query y el fragmento llegan acá vacíos: un ? o un # solo pueden estar al final, y
        // dejarlos mandaría las requests a https://x?/v1/… (o a https://x#/v1/…, que es /).
        return rtrim($url, '/?#');
    }

    /** Segundos por intento: un número finito, mayor que 0. */
    public static function timeout(mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new \TypeError(
                'timeout tiene que ser un número de segundos, como 65, no ' . get_debug_type($value)
                . '.',
            );
        }
        if (!is_finite((float) $value) || $value <= 0) {
            throw new \ValueError('timeout tiene que ser un número de segundos mayor que 0.');
        }
        if ($value > self::MAX_TIMEOUT) {
            throw new \ValueError(
                'timeout no puede pasar de ' . self::MAX_TIMEOUT . ' segundos (unos 24 días).',
            );
        }
        return $value;
    }

    public static function maxNetworkRetries(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \TypeError('max_network_retries tiene que ser un int.');
        }
        if ($value < 0) {
            throw new \ValueError('max_network_retries no puede ser negativo.');
        }
        return $value;
    }

    /**
     * Tu plugin, para el User-Agent: name, y version y url opcionales.
     *
     * @return array{name: string, version?: string, url?: string}
     */
    public static function appInfo(mixed $info): array
    {
        // Una lista (['mi-plugin', '1.2.0']) no es un dict en Python ni un objeto en Node.
        if (!is_array($info) || ($info !== [] && array_is_list($info))) {
            throw new \TypeError(
                "app_info tiene que ser un array como ['name' => 'mi-plugin', 'version' => "
                . "'1.2.0'].",
            );
        }
        $unknown = array_diff(
            array_map(static fn(int|string $key): string => (string) $key, array_keys($info)),
            self::APP_INFO_FIELDS,
        );
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new \ValueError("app_info no acepta '{$unknown[0]}': usá name, version y url.");
        }
        $name = $info['name'] ?? null;
        if (!is_string($name) || Text::isBlank($name)) {
            throw new \ValueError('app_info necesita un name.');
        }
        $result = ['name' => $name];
        foreach (['version', 'url'] as $field) {
            $value = $info[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new \TypeError("app_info['{$field}'] tiene que ser un string.");
            }
            $result[$field] = $value;
        }
        foreach ($result as $value) {
            // Va en un header: nada de saltos de línea (CRLF) ni de caracteres fuera de ASCII.
            if (\preg_match('/\A[\x20-\x7e]*\z/', $value) !== 1) {
                throw new \ValueError(
                    'app_info solo admite caracteres ASCII visibles: va en el header User-Agent.',
                );
            }
        }
        return $result;
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string,
     *     path?: string, query?: string, fragment?: string} $parts
     */
    private static function isAllowedBase(array $parts): bool
    {
        $scheme = Text::asciiLower($parts['scheme'] ?? '');
        $host = Text::asciiLower($parts['host'] ?? '');
        $secure = $scheme === 'https' && $host !== '';
        $local = $scheme === 'http' && \in_array($host, self::LOCAL_HOSTS, true);
        // Una query o un fragmento vacíos ("https://x?") cuentan como ausentes, como en Python y
        // Node (apiBase() les saca el ? o el #).
        $extra = ($parts['query'] ?? '') . ($parts['fragment'] ?? '') . ($parts['user'] ?? '')
            . ($parts['pass'] ?? '');
        return ($secure || $local) && $extra === '';
    }
}
