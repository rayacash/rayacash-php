<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\Internal\ClientConfig;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Env;
use RayaCash\Internal\HttpClientManager;
use RayaCash\Internal\Hydrator;
use RayaCash\Internal\ObjectToken;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\Internal\Secret;
use RayaCash\Internal\Text;
use RayaCash\Internal\UserAgent;
use RayaCash\Internal\Validation;
use RayaCash\Service\BalanceService;
use RayaCash\Service\ConnectService;
use RayaCash\Service\IdentityService;
use RayaCash\Service\OAuthService;
use RayaCash\Service\PaymentService;
use RayaCash\Service\SandboxHelpersService;
use RayaCash\Service\TransferService;
use RayaCash\Service\UserService;

/**
 * Cliente de la API de Raya Cash.
 *
 * ```php
 * $client = new RayaClient(); // lee la clave de RAYA_SECRET_KEY
 * $balance = $client->balance->retrieve();
 * ```
 *
 * La clave secreta va solo en tu servidor, leída de una variable de entorno o de tu config:
 * nunca en el navegador, en una app mobile ni en el repositorio. Todo lo que pasás se valida al
 * construirlo, antes de cualquier request.
 *
 * Si tu aplicación cobra a nombre de otras cuentas (Raya Connect), un RayaClient armado con el
 * access_token de la cuenta conectada (raya_at_live_…) opera por ella: `$connected->users` y
 * `$connected->connect->orders`. Los payouts (`$client->connect->payouts`), que salen de tu
 * saldo, van con tu clave secreta, como el resto de la API. `$client->oauth->token()` no necesita
 * clave.
 *
 * En un sandbox, el cliente va con su clave secreta de prueba (raya_sk_test_…) y la misma
 * api_base: la clave decide el entorno. `$client->testHelpers` solo funciona ahí.
 *
 * @final
 */
class RayaClient
{
    private const OPTIONS = [
        'api_key',
        'api_base',
        'timeout',
        'max_network_retries',
        'http_client',
        'app_info',
    ];

    /** Tu saldo disponible. */
    public readonly BalanceService $balance;

    /** Enviar pesos y consultar las transferencias enviadas y recibidas. */
    public readonly TransferService $transfers;

    /** Cobrar por transferencia, con un CVU exclusivo por pago. */
    public readonly PaymentService $payments;

    /** Canjear el code de una cuenta que conectó tu aplicación por su access_token. */
    public readonly OAuthService $oauth;

    /** Los datos de las cuentas que conectaron tu aplicación (con su access_token). */
    public readonly UserService $users;

    /**
     * Raya Connect: `orders` cobra a nombre de una cuenta conectada, con tu comisión (con su
     * access_token), y `payouts` envía pesos desde tu saldo a tus vendedores (con tu clave
     * secreta).
     */
    public readonly ConnectService $connect;

    /**
     * Identidad: `verifications` verifica la identidad de tus clientes (DNI, prueba de vida y
     * RENAPER), con tu clave secreta.
     */
    public readonly IdentityService $identity;

    /**
     * Lo que solo existe en un sandbox, con su clave secreta de prueba (raya_sk_test_…):
     * `testHelpers->payments->simulateTransfer()` simula la transferencia de un pago de prueba.
     */
    public readonly SandboxHelpersService $testHelpers;

    /**
     * El nombre de `$connect` en la 1.1.0: el mismo objeto, así `$client->checkout->orders`
     * sigue andando (y llama a /v1/connect/orders).
     *
     * Para tu IDE y PHPStan es un CheckoutService, como en la 1.1.0 (en PHP, CheckoutService es
     * ConnectService): así `$client->checkout->orders->create()` les devuelve un Checkout, como
     * antes (ver src/Service/CheckoutService.php).
     *
     * @deprecated Desde la 1.2.0: usá `$client->connect`, que es el mismo objeto. Se elimina en
     *     la 2.0.0.
     * @var Service\CheckoutService
     */
    public readonly ConnectService $checkout;

    private readonly ClientConfig $config;
    private readonly Requestor $requestor;

    /**
     * new RayaClient(), new RayaClient('raya_sk_live_…'), new RayaClient('raya_sk_live_…',
     * ['timeout' => 90]), new RayaClient(['api_key' => …, 'timeout' => 90]) o
     * new RayaClient(config: ['timeout' => 90]). Un valor en null usa el de por defecto.
     *
     * - api_key: tu clave secreta (raya_sk_live_…) o el access_token de una cuenta conectada
     *   (raya_at_live_…); en un sandbox, sus pares de prueba (raya_sk_test_… y raya_at_test_…).
     *   Si no la pasás, se lee de RAYA_SECRET_KEY.
     * - api_base: por defecto, https://api.raya.cash.
     * - timeout: segundos por intento (por defecto 65, con 10 para conectar). El servidor corta a
     *   los 60 s y crear un pago puede tardar ~40 s.
     * - max_network_retries: reintentos ante cortes, 408, 429, 5xx e IDEMPOTENCY_KEY_IN_USE
     *   (por defecto 2). Los POST reintentan con la misma Idempotency-Key: nunca se ejecutan dos
     *   veces.
     * - http_client: un HttpClientInterface propio (un CurlClient con proxy o certificados, o uno
     *   falso para tus tests). No lo cerramos.
     * - app_info: ['name' => …, 'version' => …, 'url' => …] de tu plugin, para el User-Agent.
     *
     * @param string|array{
     *     api_key?: string|null,
     *     api_base?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     *     http_client?: HttpClientInterface|null,
     *     app_info?: array{name: string, version?: string|null, url?: string|null}|null,
     * }|null $apiKey
     * @param array{
     *     api_key?: string|null,
     *     api_base?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     *     http_client?: HttpClientInterface|null,
     *     app_info?: array{name: string, version?: string|null, url?: string|null}|null,
     * } $config
     * @throws Exception\AuthenticationException si la clave no puede andar (vacía, pública, con
     *     espacios).
     */
    public function __construct(
        #[\SensitiveParameter]
        string|array|null $apiKey = null,
        #[\SensitiveParameter]
        array $config = [],
    ) {
        if (is_array($apiKey)) {
            if ($config !== []) {
                throw new \TypeError(
                    "Pasá las opciones en un solo array: new RayaClient(['api_key' => …, "
                    . "'timeout' => …]).",
                );
            }
            $options = $apiKey;
            $given = $options['api_key'] ?? null;
        } else {
            if ($apiKey !== null && array_key_exists('api_key', $config)) {
                throw new \TypeError(
                    'Pasaste la clave dos veces: como primer argumento y en api_key.',
                );
            }
            $options = $config;
            $given = $apiKey ?? $config['api_key'] ?? null;
        }
        // new RayaClient([$clave]), config: [$clave] o ($clave, [90]): sin nombres, no se sabe
        // qué es cada valor (y nunca se muestra).
        if ($options !== [] && array_is_list($options)) {
            throw new \TypeError(
                "RayaClient recibe la clave como string, new RayaClient('raya_sk_live_…'), o las "
                . "opciones en un array con nombres, como ['api_key' => …].",
            );
        }
        foreach (array_keys($options) as $key) {
            $key = (string) $key;
            if (!\in_array($key, self::OPTIONS, true)) {
                throw new \TypeError(sprintf(
                    'RayaClient no acepta la opción "%s"%s: las opciones son api_key, api_base, '
                    . 'timeout, max_network_retries, http_client y app_info.',
                    $key,
                    Text::snakeHint($key, self::OPTIONS),
                ));
            }
        }

        // La variable de entorno se lee una vez, ahora (el estilo global la lee en cada request).
        $key = $given ?? Env::get('RAYA_SECRET_KEY');
        $secret = $key === null ? null : Secret::of(Validation::apiKey($key));

        $http = $options['http_client'] ?? null;
        if ($http !== null && !$http instanceof HttpClientInterface) {
            throw new \TypeError(
                'http_client tiene que implementar RayaCash\HttpClient\HttpClientInterface (como '
                . 'CurlClient), no ' . get_debug_type($http) . '.',
            );
        }
        $apiBase = Validation::apiBase($options['api_base'] ?? RayaCash::DEFAULT_API_BASE);
        $timeout = isset($options['timeout'])
            ? Validation::timeout($options['timeout'])
            : RayaCash::DEFAULT_TIMEOUT;
        $retries = isset($options['max_network_retries'])
            ? Validation::maxNetworkRetries($options['max_network_retries'])
            : RayaCash::DEFAULT_MAX_NETWORK_RETRIES;
        $appInfo = isset($options['app_info']) ? Validation::appInfo($options['app_info']) : null;

        $this->config = new ClientConfig(
            $secret,
            $apiBase,
            (float) $timeout,
            $retries,
            UserAgent::build($appInfo),
            new HttpClientManager($http),
        );
        $this->requestor = new Requestor($this->config);
        $this->balance = new BalanceService($this->requestor);
        $this->transfers = new TransferService($this->requestor);
        $this->payments = new PaymentService($this->requestor);
        $this->oauth = new OAuthService($this->requestor);
        $this->users = new UserService($this->requestor);
        $this->connect = new ConnectService($this->requestor);
        // El mismo objeto. PHPStan no ve el alias: para él, el CheckoutService de $checkout es una
        // subclase de ConnectService (la declaración de src/Service/CheckoutService.php), y en PHP
        // es la misma clase.
        // @phpstan-ignore assign.propertyType
        $this->checkout = $this->connect;
        $this->identity = new IdentityService($this->requestor);
        $this->testHelpers = new SandboxHelpersService($this->requestor);
    }

    /**
     * Una request a cualquier ruta, con la misma autenticación, reintentos y errores: para
     * endpoints que el SDK todavía no tiene.
     *
     * `$client->rawRequest('get', '/v1/…', params: ['limit' => 5])` o
     * `$client->rawRequest('post', '/v1/…', body: ['a' => 1], options: ['idempotency_key' => $k])`.
     * params va en la query y body, como JSON en el POST. Devuelve el objeto según su "object";
     * una lista se puede paginar.
     *
     * @param array<string, mixed>|null $params
     * @param array<string, mixed>|\stdClass|null $body
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     *     idempotency_key?: string|null,
     * } $options
     * @throws Exception\RayaException
     */
    public function rawRequest(
        string $method,
        string $path,
        ?array $params = null,
        array|\stdClass|null $body = null,
        #[\SensitiveParameter]
        array $options = [],
    ): RayaObject {
        if ($params !== null && $params !== [] && array_is_list($params)) {
            throw new \TypeError(
                'params tiene que ser un array con los nombres de los filtros, no una lista: va en '
                . 'la query.',
            );
        }
        if (is_array($body) && $body !== [] && array_is_list($body)) {
            throw new \TypeError(
                'body tiene que ser un array con los nombres de los campos (o un stdClass), no una '
                . 'lista: va como JSON en el POST.',
            );
        }
        $query = $params === null ? [] : Encoding::query($params);
        $opts = RequestOptions::check($options);
        $result = $this->requestor->request($method, $path, $query, $body, $opts);
        if ($result instanceof ListObject && Text::asciiUpper($method) === 'GET') {
            ObjectToken::of($result)->setContext($path, $query, $opts);
        }
        return $result;
    }

    /**
     * Como Webhook::constructEvent, con el evento ligado a este cliente: así,
     * `$event->data->object->refund()` usa la clave de este cliente.
     *
     * @param string|\Stringable $payload El body crudo, sin parsear.
     * @param string|null $sigHeader El header Raya-Signature.
     * @param int|float|null $tolerance Segundos aceptados entre t y tu reloj; null o 0 lo apagan.
     * @throws Exception\SignatureVerificationException si la firma no es válida.
     */
    public function constructEvent(
        mixed $payload,
        mixed $sigHeader,
        #[\SensitiveParameter]
        ?string $secret,
        mixed $tolerance = Webhook::DEFAULT_TOLERANCE,
    ): Event {
        $raw = Encoding::webhookPayload($payload);
        WebhookSignature::verifyHeader($raw, $sigHeader, $secret, $tolerance);
        return Hydrator::event($raw, $this->requestor);
    }

    /**
     * Suelta la conexión del cliente HTTP propio, para workers largos. Se puede llamar más de una
     * vez y el cliente sigue usable: la próxima request abre otra. El que pasaste en http_client
     * no se toca.
     */
    public function close(): void
    {
        $this->config->http()->close();
    }

    /**
     * Lo que muestran var_dump y print_r: la clave, enmascarada.
     *
     * @return array{api_base: string, api_key: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'api_base' => $this->config->apiBase(),
            'api_key' => $this->config->apiKey()?->masked(),
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException(
            'Un RayaClient no se puede serializar (no guardamos la clave): creá uno en cada '
            . 'proceso o resolvelo donde lo uses.',
        );
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException(
            'Un RayaClient no se puede serializar (no guardamos la clave): creá uno en cada '
            . 'proceso o resolvelo donde lo uses.',
        );
    }
}
