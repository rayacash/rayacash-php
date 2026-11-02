<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\ConflictException;
use RayaCash\Exception\IdempotencyException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\NotFoundException;
use RayaCash\Exception\PermissionDeniedException;
use RayaCash\Exception\RateLimitException;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\HttpClient\CurlClient;
use RayaCash\HttpClient\TransportException;
use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Secret;
use RayaCash\ListObject;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\RayaClient;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\MockApi;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\Webhook;
use RayaCash\WebhookSignature;

/**
 * La clave nunca aparece completa (invariante 11 del contrato común de los SDKs de Raya), ni el
 * secreto de los webhooks.
 *
 * Python lo mira con repr() (test_repr_never_shows_the_key) y Node con util.inspect y
 * JSON.stringify («console.log nunca muestra la clave»). PHP tiene más formas de mirar un objeto,
 * y acá se prueban todas: var_dump, print_r, var_export, debug_zval_dump, json_encode, serialize,
 * un (array) recorrido entero (lo que hacen dd() de Laravel, VarDumper y Monolog) y el texto de
 * los Stringable. Además, las trazas de las excepciones guardan los argumentos de cada llamada:
 * con #[\SensitiveParameter] (PHP 8.2+), los que llevan una clave salen como
 * SensitiveParameterValue. En PHP 8.1 el atributo no existe, y la traza solo queda limpia con
 * zend.exception_ignore_args=On (el default de php.ini-production, lo que recomienda el README).
 *
 * Las salidas exactas de __debugInfo() están en ClientTest (testReprNeverShowsTheKey) e
 * InternalsTest (testResponseRepr).
 */
final class SecurityTest extends TestCase
{
    /** Como en producción (php.ini-production): las trazas sin argumentos. */
    private const PRODUCTION = ['zend.exception_ignore_args' => '1'];

    /** Como en desarrollo: con los argumentos, y los strings enteros en getTraceAsString(). */
    private const DEVELOPMENT = [
        'zend.exception_ignore_args' => '0',
        'zend.exception_string_param_max_len' => '1000000',
    ];

    private const MASKED = 'raya_sk_live_…IJKL';

    /** La contraseña de un proxy o de un certificado, en las opciones de un CurlClient. */
    private const PROXY_PASSWORD = 'proxyPASSWORDs3cr3t';

    // --- El cliente ------------------------------------------------------------------------------

    /**
     * Las formas de pasarle la clave a un RayaClient.
     *
     * @return iterable<string, array{\Closure(MockApi): RayaClient}>
     */
    public static function clients(): iterable
    {
        yield 'argumento' => [
            static fn(MockApi $api): RayaClient => new RayaClient(
                Fixtures::TEST_KEY,
                ['http_client' => $api],
            ),
        ];
        yield 'array' => [
            static fn(MockApi $api): RayaClient => new RayaClient(
                ['api_key' => Fixtures::TEST_KEY, 'http_client' => $api],
            ),
        ];
        yield 'config:' => [
            static fn(MockApi $api): RayaClient => new RayaClient(
                config: ['api_key' => Fixtures::TEST_KEY, 'http_client' => $api],
            ),
        ];
        yield 'RAYA_SECRET_KEY' => [
            static function (MockApi $api): RayaClient {
                putenv('RAYA_SECRET_KEY=' . Fixtures::TEST_KEY);
                return new RayaClient(config: ['http_client' => $api]);
            },
        ];
    }

    /**
     * test_repr_never_shows_the_key (Python) y «console.log nunca muestra la clave» (Node), con
     * cada forma de mirar un objeto en PHP: también después de una request, con el requestor y
     * el cliente HTTP ya en uso.
     *
     * @param \Closure(MockApi): RayaClient $build
     */
    #[DataProvider('clients')]
    public function testClientNeverShowsTheKey(\Closure $build): void
    {
        $client = $build($this->api);
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve();
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->authorization());

        $shown = self::dumps('client', $client);
        self::assertNoSecrets($shown);
        // var_dump, print_r y debug_zval_dump la muestran enmascarada (__debugInfo).
        self::assertSame(
            "RayaCash\\RayaClient Object\n(\n    [api_base] => https://api.raya.cash\n"
            . '    [api_key] => ' . self::MASKED . "\n)\n",
            $shown['client: print_r'],
        );
        self::assertStringContainsString('"' . self::MASKED . '"', $shown['client: var_dump']);
        self::assertStringContainsString(self::MASKED, $shown['client: debug_zval_dump']);
    }

    /**
     * Un RayaClient no se serializa: guardaría la clave (en una cola, en una sesión, en una
     * caché). Tampoco dentro de otra cosa, ni al revés con un payload armado a mano.
     */
    public function testClientCannotBeSerialized(): void
    {
        $message = 'Un RayaClient no se puede serializar (no guardamos la clave): creá uno en cada '
            . 'proceso o resolvelo donde lo uses.';
        $client = $this->api->client();
        $error = self::assertThrows(\LogicException::class, static fn() => serialize($client));
        self::assertSame($message, $error->getMessage());

        // Como el job de una cola que lo guarda en una propiedad.
        $job = new \stdClass();
        $job->raya = $client;
        self::assertThrows(\LogicException::class, static fn() => serialize($job), $message);
        self::assertThrows(\LogicException::class, static fn() => serialize([$client]), $message);
        self::assertThrows(
            \LogicException::class,
            static fn() => unserialize('O:19:"RayaCash\RayaClient":0:{}'),
            $message,
        );
        self::assertSame([], $this->api->requests);
    }

    /**
     * La clave sola tampoco: Internal\Secret, donde la guardan el cliente, RayaCash::setApiKey()
     * y las opciones de cada llamada, no se serializa ni se arma desde un payload. Ni se clona: el
     * texto vive en un WeakMap, por objeto, y el clon quedaría sin la clave.
     */
    public function testTheKeyCannotBeSerializedOrCopied(): void
    {
        $message = 'La clave secreta no se serializa: armá el cliente en cada proceso.';
        $secret = Secret::of(Fixtures::TEST_KEY);
        self::assertThrows(\LogicException::class, static fn() => serialize($secret), $message);
        self::assertThrows(
            \LogicException::class,
            static fn() => unserialize('O:24:"RayaCash\Internal\Secret":0:{}'),
            $message,
        );
        // El error es del motor, y su texto cambia entre versiones: «Call to private
        // RayaCash\Internal\Secret::__clone() from …» (8.5 suma «method»).
        $error = self::thrown(static fn() => clone $secret);
        self::assertSame(\Error::class, $error::class);
        self::assertStringContainsString('Secret::__clone()', $error->getMessage());
        self::assertSame(Fixtures::TEST_KEY, $secret->reveal());
    }

    // --- El estilo global ------------------------------------------------------------------------

    /**
     * RayaCash::setApiKey() guarda la clave fuera de cualquier propiedad, y no hay getApiKey().
     * La de RAYA_SECRET_KEY se lee en cada request y no queda guardada.
     */
    public function testGlobalConfigNeverShowsTheKey(): void
    {
        self::assertSame([], preg_grep('/\Aget.*key\z/i', get_class_methods(RayaCash::class)));
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        RayaCash::setHttpClient($this->api);
        $this->api->add(200, Fixtures::BALANCE);
        $balance = Balance::retrieve();
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->authorization());
        $this->api->add(200, Fixtures::transferPage(['t1'], 'c1'));
        $page = Transfer::list([], ['api_key' => Fixtures::OTHER_KEY]);
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->authorization());
        self::assertNoSecrets(
            self::globalDumps() + self::dumps('balance', $balance) + self::dumps('page', $page),
        );

        RayaCash::setApiKey(null);
        putenv('RAYA_SECRET_KEY=' . Fixtures::OTHER_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->authorization());
        self::assertNoSecrets(self::globalDumps());
    }

    // --- Las listas, las requests y las respuestas -----------------------------------------------

    /**
     * Una lista pedida con otra clave la recuerda para pedir las páginas siguientes con la misma,
     * pero fuera de sus datos: ningún volcado la muestra, en ninguna página.
     */
    public function testPageFetchedWithAPerCallKeyNeverShowsIt(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::transferPage(['t1', 't2'], 'c1'));
        $this->api->add(200, Fixtures::transferPage(['t3'], null));
        $page = $client->transfers->list(['limit' => 2], ['api_key' => Fixtures::OTHER_KEY]);
        $second = $page->nextPage();
        // La segunda página salió con la clave de la llamada: la lista sí la tiene.
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->authorization());
        self::assertSame(['limit' => '2', 'cursor' => 'c1'], $this->api->last()->query);
        // La última página copia el contexto (sin request): tampoco la muestra.
        $end = $second->nextPage();
        self::assertCount(2, $this->api->requests);

        // Lo mismo con una lista de rawRequest (su contexto guarda las mismas opciones).
        $this->api->add(200, Fixtures::transferPage(['t4'], 'c4'));
        $raw = $client->rawRequest(
            'get',
            '/v1/transfers',
            params: ['limit' => 1],
            options: ['api_key' => Fixtures::OTHER_KEY],
        );
        self::assertInstanceOf(ListObject::class, $raw);

        self::assertNoSecrets(
            self::dumps('page', $page)
            + self::dumps('data', $page->data)
            + self::dumps('response', $page->getLastResponse())
            + self::dumps('clone', clone $page)
            + self::dumps('second', $second)
            + self::dumps('end', $end)
            + self::dumps('raw', $raw)
            + self::dumps('client', $client),
        );
    }

    /**
     * La Request que recibe tu HttpClientInterface: la clave sale solo por headers() (para
     * mandarla), nunca en un volcado. var_dump y print_r la muestran enmascarada.
     */
    public function testRequestRevealsTheKeyOnlyInItsHeaders(): void
    {
        $client = $this->api->client();
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(
            ['amount' => 15000, 'description' => 'Pedido #1042'],
            ['idempotency_key' => 'clave-1'],
        );
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]);
        [$post, $get] = $this->api->requests;

        $cases = ['POST' => [$post, Fixtures::TEST_KEY], 'GET' => [$get, Fixtures::OTHER_KEY]];
        foreach ($cases as $method => [$recorded, $key]) {
            $request = $recorded->request;
            self::assertSame($method, $request->method);
            self::assertSame('Bearer ' . $key, $request->header('Authorization'));
            self::assertSame('Bearer ' . $key, $request->headers()['Authorization']);
            $shown = self::dumps("{$method} request", $request);
            self::assertNoSecrets($shown);
            $masked = 'Bearer raya_sk_live_…' . substr($key, -4);
            self::assertStringContainsString($masked, $shown["{$method} request: print_r"]);
            self::assertStringContainsString($masked, $shown["{$method} request: var_dump"]);
        }
    }

    /**
     * La respuesta detrás de un objeto: ni la clave ni (en var_dump y print_r, como el repr de
     * Python) el body, que trae nombres, CUIT y CBU.
     */
    public function testResponseNeverShowsTheKey(): void
    {
        $this->api->add(201, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $this->api->client()->payments->create(
            ['amount' => 15000],
            ['idempotency_key' => 'clave-1', 'api_key' => Fixtures::OTHER_KEY],
        );
        $response = $payment->getLastResponse();
        self::assertNotNull($response);
        $shown = self::dumps('response', $response);
        self::assertNoSecrets($shown);
        foreach (['var_dump', 'print_r', 'debug_zval_dump'] as $how) {
            $text = $shown["response: {$how}"];
            self::assertStringContainsString('req_test00000000000000000001', $text);
            // El CUIT de quien pagó, que está en el body y en data.
            self::assertStringNotContainsString('27123456781', $text);
        }
    }

    // --- Las excepciones -------------------------------------------------------------------------

    /**
     * Cada excepción que el SDK lanza con una clave en juego: la del cliente, una por llamada o
     * el secreto de un webhook. El bool dice si la clave pasó como argumento por el SDK (y por lo
     * tanto, en PHP 8.2+, tiene que aparecer un SensitiveParameterValue en la traza).
     *
     * @return iterable<string, array{class-string<\Throwable>, \Closure(MockApi): mixed, bool}>
     */
    public static function exceptions(): iterable
    {
        yield 'AuthenticationException: una clave inválida' => [
            AuthenticationException::class,
            static fn(MockApi $api): mixed => new RayaClient(Fixtures::TEST_KEY . ' '),
            true,
        ];
        yield 'AuthenticationException de la API' => [
            AuthenticationException::class,
            static function (MockApi $api): mixed {
                $api->addError(401, 'API_KEY_EXPIRED');
                return $api->client()->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]);
            },
            true,
        ];
        yield 'PermissionDeniedException' => [
            PermissionDeniedException::class,
            static function (MockApi $api): mixed {
                $api->addError(403, 'API_NOT_ENABLED');
                return $api->client()->transfers->list([], ['api_key' => Fixtures::OTHER_KEY]);
            },
            true,
        ];
        yield 'InvalidRequestException' => [
            InvalidRequestException::class,
            static function (MockApi $api): mixed {
                $api->addError(400, 'PARAMETER_INVALID', data: ['field' => 'amount']);
                return $api->client()->transfers->create(
                    ['amount' => 1, 'destination' => 'proveedor.ejemplo'],
                    ['idempotency_key' => 'k', 'api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'NotFoundException' => [
            NotFoundException::class,
            static function (MockApi $api): mixed {
                $api->addError(404, 'PAYMENT_NOT_FOUND');
                return $api->client()->payments->retrieve(
                    Fixtures::PAYMENT_ID,
                    ['api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'ConflictException' => [
            ConflictException::class,
            static function (MockApi $api): mixed {
                $api->addError(409, 'PAYMENT_NOT_REFUNDABLE', data: ['status' => 'refunded']);
                return $api->client()->payments->refund(
                    Fixtures::PAYMENT_ID,
                    ['api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'IdempotencyException' => [
            IdempotencyException::class,
            static function (MockApi $api): mixed {
                $api->addError(409, 'IDEMPOTENCY_KEY_REUSED');
                return $api->client()->payments->create(
                    ['amount' => 15000],
                    ['idempotency_key' => 'k', 'api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'UnprocessableEntityException' => [
            UnprocessableEntityException::class,
            static function (MockApi $api): mixed {
                $api->addError(422, 'INSUFFICIENT_BALANCE');
                return $api->client()->transfers->create(
                    ['amount' => 10 ** 9, 'destination' => 'alias.prueba'],
                    ['idempotency_key' => 'k', 'api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'RateLimitException' => [
            RateLimitException::class,
            static function (MockApi $api): mixed {
                $api->addError(429, 'RATE_LIMITED', headers: ['Retry-After' => '120']);
                return $api->client()->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]);
            },
            true,
        ];
        yield 'ApiException con el estilo global' => [
            ApiException::class,
            static function (MockApi $api): mixed {
                RayaCash::setApiKey(Fixtures::TEST_KEY);
                RayaCash::setHttpClient($api);
                $api->addError(503, 'API_UNAVAILABLE');
                return Balance::retrieve();
            },
            false,
        ];
        // Cada estático del estilo global, y $payment->refund(), con una clave por llamada: sus
        // opciones también llevan #[\SensitiveParameter] (no solo las del servicio que llaman).
        $other = ['api_key' => Fixtures::OTHER_KEY];
        $global = [
            'Balance::retrieve' => static fn(): mixed => Balance::retrieve($other),
            'Transfer::create' => static fn(): mixed => Transfer::create(
                ['amount' => 1, 'destination' => 'proveedor.ejemplo'],
                ['idempotency_key' => 'k', ...$other],
            ),
            'Transfer::retrieve' => static fn(): mixed => Transfer::retrieve('t', $other),
            'Transfer::list' => static fn(): mixed => Transfer::list([], $other),
            'Payment::create' => static fn(): mixed => Payment::create(['amount' => 1], $other),
            'Payment::retrieve' => static fn(): mixed => Payment::retrieve('p', $other),
            'Payment::refundById' => static fn(): mixed => Payment::refundById('p', $other),
            '$payment->refund' => static fn(): mixed => Payment::constructFrom(
                ['object' => 'payment', 'id' => 'p'],
            )->refund($other),
        ];
        foreach ($global as $name => $call) {
            yield "ApiException de {$name} con una clave por llamada" => [
                ApiException::class,
                static function (MockApi $api) use ($call): mixed {
                    RayaCash::setApiKey(Fixtures::TEST_KEY);
                    RayaCash::setHttpClient($api);
                    $api->addError(503, 'API_UNAVAILABLE');
                    return $call();
                },
                true,
            ];
        }
        yield 'ApiException de rawRequest' => [
            ApiException::class,
            static function (MockApi $api): mixed {
                $api->add(502, text: '<html>Bad gateway</html>');
                return $api->client(config: ['max_network_retries' => 0])->rawRequest(
                    'post',
                    '/v1/algo_nuevo',
                    body: ['a' => 1],
                    options: ['api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'ApiException: otro tipo de objeto' => [
            ApiException::class,
            static function (MockApi $api): mixed {
                $api->add(200, Fixtures::TRANSFER_INBOUND);
                return $api->client()->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]);
            },
            true,
        ];
        yield 'ApiException: un 200 que no es JSON' => [
            ApiException::class,
            static function (MockApi $api): mixed {
                $api->add(200, text: 'no es json');
                return $api->client()->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]);
            },
            true,
        ];
        yield 'ApiConnectionException' => [
            ApiConnectionException::class,
            static function (MockApi $api): mixed {
                $api->failWith(TransportException::network('sin red'));
                return $api->client(config: ['max_network_retries' => 0])->payments->create(
                    ['amount' => 15000],
                    ['api_key' => Fixtures::OTHER_KEY],
                );
            },
            true,
        ];
        yield 'SignatureVerificationException' => [
            SignatureVerificationException::class,
            static fn(MockApi $api): mixed => $api->client()->constructEvent(
                Fixtures::WEBHOOK_TEST_BODY,
                't=' . Fixtures::WEBHOOK_TEST_TIMESTAMP . ',v1=' . str_repeat('0', 64),
                Fixtures::WEBHOOK_SECRET,
            ),
            true,
        ];
        yield 'SignatureVerificationException de Webhook::constructEvent' => [
            SignatureVerificationException::class,
            static fn(MockApi $api): mixed => Webhook::constructEvent(
                Fixtures::WEBHOOK_TEST_BODY,
                't=' . Fixtures::WEBHOOK_TEST_TIMESTAMP . ',v1=' . str_repeat('0', 64),
                Fixtures::WEBHOOK_SECRET,
            ),
            true,
        ];
        yield 'ValueError: el webhook no es JSON' => [
            \ValueError::class,
            static function (MockApi $api): mixed {
                $body = 'no es json';
                $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
                return $api->client()->constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
            },
            true,
        ];
        yield 'TypeError: la clave dos veces' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient(
                Fixtures::TEST_KEY,
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            true,
        ];
        yield 'TypeError: dos arrays' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient(
                ['api_key' => Fixtures::TEST_KEY],
                ['timeout' => 90],
            ),
            true,
        ];
        yield 'TypeError: una opción desconocida del cliente' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient(['apiKey' => Fixtures::TEST_KEY]),
            true,
        ];
        // La clave (o una opción) en una lista, en cualquier argumento del constructor.
        yield 'TypeError: la clave en una lista' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient([Fixtures::TEST_KEY]),
            true,
        ];
        yield 'TypeError: la clave en una lista en config:' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient(config: [Fixtures::TEST_KEY]),
            true,
        ];
        yield 'TypeError: la clave en una lista como segundo argumento' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient(null, [Fixtures::TEST_KEY]),
            true,
        ];
        yield 'TypeError: las opciones del cliente en una lista' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => new RayaClient(Fixtures::TEST_KEY, [90]),
            true,
        ];
        yield 'ValueError: una opción inválida del cliente' => [
            \ValueError::class,
            static fn(MockApi $api): mixed => new RayaClient(Fixtures::TEST_KEY, ['timeout' => 0]),
            true,
        ];
        yield 'TypeError: una opción desconocida por llamada' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => $api->client()->balance->retrieve(
                ['apiKey' => Fixtures::OTHER_KEY],
            ),
            true,
        ];
        yield 'TypeError: las opciones de una llamada en una lista' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => $api->client()->payments->retrieve(
                Fixtures::PAYMENT_ID,
                [Fixtures::OTHER_KEY],
            ),
            true,
        ];
        yield 'TypeError: las opciones de rawRequest en una lista' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => $api->client()->rawRequest(
                'get',
                '/v1/balance',
                options: [Fixtures::OTHER_KEY],
            ),
            true,
        ];
        yield 'ValueError: una Idempotency-Key en un GET' => [
            \ValueError::class,
            static fn(MockApi $api): mixed => $api->client()->transfers->retrieve(
                't',
                ['api_key' => Fixtures::OTHER_KEY, 'idempotency_key' => 'k'],
            ),
            true,
        ];
        yield 'TypeError: la clave en lugar de las opciones' => [
            \TypeError::class,
            static fn(MockApi $api): mixed => $api->client()->balance->retrieve(
                // @phpstan-ignore argument.type (el error de quien la pasa sin el array)
                Fixtures::OTHER_KEY,
            ),
            true,
        ];
        yield 'LogicException: serialize de un RayaClient' => [
            \LogicException::class,
            static fn(MockApi $api): mixed => serialize($api->client()),
            false,
        ];
        yield 'LogicException: una lista deserializada' => [
            \LogicException::class,
            static function (MockApi $api): mixed {
                $api->add(200, Fixtures::TRANSFER_LIST);
                $page = $api->client()->transfers->list([], ['api_key' => Fixtures::OTHER_KEY]);
                $restored = unserialize(serialize($page));
                self::assertInstanceOf(ListObject::class, $restored);
                return $restored->nextPage();
            },
            false,
        ];
        yield 'OutOfBoundsException' => [
            \OutOfBoundsException::class,
            static function (MockApi $api): mixed {
                $api->add(200, Fixtures::TRANSFER_LIST);
                $page = $api->client()->transfers->list([], ['api_key' => Fixtures::OTHER_KEY]);
                return $page[0];
            },
            false,
        ];
    }

    /**
     * Cada excepción, con las trazas como en producción (sin argumentos): ningún volcado de la
     * excepción ni de las anteriores muestra un secreto. En PHP 8.2+, también con las trazas
     * como en desarrollo: cada argumento con una clave sale como SensitiveParameterValue.
     *
     * @param class-string<\Throwable> $class
     * @param \Closure(MockApi): mixed $call
     */
    #[DataProvider('exceptions')]
    public function testExceptionsNeverShowTheKey(
        string $class,
        \Closure $call,
        bool $redacted,
    ): void {
        self::assertSecretsStayOut($class, fn(): mixed => $call($this->api), $redacted);
    }

    /**
     * Una clave fuera de lugar, que el SDK detecta y rechaza: en un argumento de más (lo que
     * frena el chequeo de argumentos) o entre los parámetros. #[\SensitiveParameter] no cubre
     * ninguno de los dos (un argumento de más no tiene parámetro; los parámetros no son
     * sensibles), así que el SDK la tiene que sacar de la traza él: también en PHP 8.1.
     *
     * @return iterable<string, array{\Closure(MockApi): mixed, class-string<\Throwable>, string}>
     */
    public static function misplacedKeys(): iterable
    {
        // Un argumento de más: las opciones con la clave quedan afuera (retrieve($id, [], $op)).
        yield 'balance->retrieve' => [
            static fn(MockApi $api): mixed => $api->client()->balance->retrieve(
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'balance->retrieve recibe 1 argumento (options), no 2',
        ];
        yield 'transfers->create' => [
            static fn(MockApi $api): mixed => $api->client()->transfers->create(
                ['amount' => 1, 'destination' => 'x'],
                ['idempotency_key' => 'k'],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'transfers->create recibe 2 argumentos (params y options), no 3',
        ];
        yield 'transfers->retrieve' => [
            static fn(MockApi $api): mixed => $api->client()->transfers->retrieve(
                't',
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'transfers->retrieve recibe 2 argumentos (transferId y options), no 3',
        ];
        yield 'transfers->list' => [
            static fn(MockApi $api): mixed => $api->client()->transfers->list(
                [],
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'transfers->list recibe 2 argumentos (params y options), no 3',
        ];
        yield 'payments->create' => [
            static fn(MockApi $api): mixed => $api->client()->payments->create(
                ['amount' => 1],
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'payments->create recibe 2 argumentos (params y options), no 3',
        ];
        yield 'payments->retrieve' => [
            static fn(MockApi $api): mixed => $api->client()->payments->retrieve(
                'p',
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'payments->retrieve recibe 2 argumentos (paymentId y options), no 3',
        ];
        yield 'payments->refund' => [
            static fn(MockApi $api): mixed => $api->client()->payments->refund(
                'p',
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'payments->refund recibe 2 argumentos (paymentId y options), no 3',
        ];
        yield 'Balance::retrieve' => [
            static fn(MockApi $api): mixed => Balance::retrieve(
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Balance::retrieve recibe 1 argumento (options), no 2',
        ];
        yield 'Transfer::create' => [
            static fn(MockApi $api): mixed => Transfer::create(
                ['amount' => 1, 'destination' => 'x'],
                ['idempotency_key' => 'k'],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Transfer::create recibe 2 argumentos (params y options), no 3',
        ];
        yield 'Transfer::retrieve' => [
            static fn(MockApi $api): mixed => Transfer::retrieve(
                't',
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Transfer::retrieve recibe 2 argumentos (transferId y options), no 3',
        ];
        yield 'Transfer::list' => [
            static fn(MockApi $api): mixed => Transfer::list(
                [],
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Transfer::list recibe 2 argumentos (params y options), no 3',
        ];
        yield 'Payment::create' => [
            static fn(MockApi $api): mixed => Payment::create(
                ['amount' => 1],
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Payment::create recibe 2 argumentos (params y options), no 3',
        ];
        yield 'Payment::retrieve' => [
            static fn(MockApi $api): mixed => Payment::retrieve(
                'p',
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Payment::retrieve recibe 2 argumentos (paymentId y options), no 3',
        ];
        yield 'Payment::refundById' => [
            static fn(MockApi $api): mixed => Payment::refundById(
                'p',
                [],
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \ArgumentCountError::class,
            'Payment::refundById recibe 2 argumentos (paymentId y options), no 3',
        ];
        yield '$payment->refund' => [
            static fn(MockApi $api): mixed => Payment::constructFrom(
                ['object' => 'payment', 'id' => 'p'],
            )->refund([], ['api_key' => Fixtures::OTHER_KEY]),
            \ArgumentCountError::class,
            '$payment->refund recibe 1 argumento (options), no 2',
        ];

        // Una opción entre los parámetros: el SDK la rechaza («va en las opciones»).
        yield 'api_key entre los parámetros de transfers->create' => [
            static fn(MockApi $api): mixed => $api->client()->transfers->create(
                ['amount' => 1, 'destination' => 'x', 'api_key' => Fixtures::OTHER_KEY],
                ['idempotency_key' => 'k'],
            ),
            \TypeError::class,
            'transfers->create no acepta api_key entre los parámetros: va en las opciones',
        ];
        yield 'apiKey entre los parámetros de payments->create' => [
            static fn(MockApi $api): mixed => $api->client()->payments->create(
                ['amount' => 1, 'apiKey' => Fixtures::OTHER_KEY],
            ),
            \TypeError::class,
            'payments->create no acepta apiKey entre los parámetros: va en las opciones',
        ];
        yield 'api_key entre los filtros de transfers->list' => [
            static fn(MockApi $api): mixed => $api->client()->transfers->list(
                ['api_key' => Fixtures::OTHER_KEY],
            ),
            \TypeError::class,
            'transfers->list no acepta api_key entre los parámetros: va en las opciones',
        ];
        yield 'api_key entre los parámetros de Payment::create' => [
            static fn(MockApi $api): mixed => Payment::create(
                ['amount' => 1, 'api_key' => Fixtures::OTHER_KEY],
            ),
            \TypeError::class,
            'payments->create no acepta api_key entre los parámetros: va en las opciones',
        ];
        yield 'apiKey entre los filtros de Transfer::list' => [
            static fn(MockApi $api): mixed => Transfer::list(['apiKey' => Fixtures::OTHER_KEY]),
            \TypeError::class,
            'transfers->list no acepta apiKey entre los parámetros: va en las opciones',
        ];
    }

    /**
     * @param \Closure(MockApi): mixed $call
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('misplacedKeys')]
    public function testMisplacedKeysStayOutOfTheTrace(
        \Closure $call,
        string $class,
        string $message,
    ): void {
        $error = self::assertSecretsStayOut(
            $class,
            fn(): mixed => $call($this->api),
            redacted: false,
            everyVersion: true,
        );
        self::assertStringContainsString($message, $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /**
     * Un plugin que trae el SDK prefijado con PHP-Scoper o Strauss (README › WordPress): los
     * namespaces pasan a ser Prefijo\RayaCash\…, pero un 'RayaCash\' escrito entre comillas
     * queda igual. La clave fuera de lugar sale igual de la traza: Params toma el namespace del
     * SDK del suyo. En otro proceso, con una copia de src/ prefijada como lo hacen ellos y las
     * trazas con argumentos (como en desarrollo).
     */
    public function testMisplacedKeysStayOutOfTheTraceWhenTheSdkIsPrefixed(): void
    {
        $copy = self::prefixedCopy('Prefijo\\');
        $code = <<<'PHP'
            <?php
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'Prefijo\RayaCash\\')) {
                    $file = substr($class, strlen('Prefijo\RayaCash\\'));
                    require COPY . '/' . str_replace('\\', '/', $file) . '.php';
                }
            });
            Prefijo\RayaCash\Internal\Transport::$factory = static function (): never {
                throw new RuntimeException('Sin red.');
            };
            $client = new Prefijo\RayaCash\RayaClient(TEST_KEY);
            $calls = [
                static fn() => $client->payments->retrieve('p', [], ['api_key' => OTHER_KEY]),
                static fn() => $client->payments->create(['amount' => 1, 'api_key' => OTHER_KEY]),
            ];
            foreach ($calls as $call) {
                try {
                    $call();
                    echo "sin error\n";
                } catch (Error $error) {
                    $classes = array_column($error->getTrace(), 'class');
                    $prefixed = preg_grep('/\APrefijo\\\\RayaCash\\\\/', $classes) !== [];
                    $leaked = str_contains(print_r($error->getTrace(), true), OTHER_KEY);
                    echo $error::class, $prefixed ? ' prefijado' : ' sin prefijo',
                        $leaked ? ' con la clave' : ' sin la clave', "\n";
                }
            }
            PHP;
        $code = strtr($code, [
            'COPY' => var_export($copy, true),
            'TEST_KEY' => var_export(Fixtures::TEST_KEY, true),
            'OTHER_KEY' => var_export(Fixtures::OTHER_KEY, true),
        ]);
        try {
            [$status, $output] = self::php(
                ['-d', 'zend.exception_ignore_args=0', '-d', 'xdebug.mode=off'],
                $code,
            );
        } finally {
            self::removeTree($copy);
        }
        self::assertSame(
            [0, "ArgumentCountError prefijado sin la clave\nTypeError prefijado sin la clave\n"],
            [$status, $output],
        );
    }

    /**
     * El CurlClient de serie, de verdad (contra un puerto cerrado de 127.0.0.1): ni él, ni el
     * error de curl, ni la ApiConnectionException guardan el header Authorization.
     */
    public function testCurlClientKeepsTheKeyOutOfItsErrors(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $failure);
        self::assertIsResource($server, "No pude escuchar en 127.0.0.1: {$failure}");
        $name = (string) stream_socket_get_name($server, false);
        fclose($server); // ya nadie escucha: curl recibe «connection refused»
        $curl = new CurlClient();
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'http_client' => $curl,
            'api_base' => 'http://' . $name,
            'timeout' => 5,
            'max_network_retries' => 0,
        ]);

        $error = self::assertSecretsStayOut(
            ApiConnectionException::class,
            static fn(): mixed => $client->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]),
            redacted: true,
        );
        self::assertSame('NETWORK_ERROR', $error->getErrorCode());
        self::assertInstanceOf(TransportException::class, $error->getPrevious());
        self::assertNoSecrets(self::dumps('curl', $curl) + self::dumps('client', $client));
    }

    /**
     * Las opciones de un CurlClient pueden llevar la contraseña del proxy o la del certificado:
     * ni él, ni un clon, ni el RayaClient o el estilo global que lo usan la muestran (var_dump y
     * print_r, solo los nombres de las opciones), y no se serializa. En 8.2+, la traza de un error
     * del constructor tampoco (#[\SensitiveParameter]).
     */
    public function testCurlClientNeverShowsItsOptions(): void
    {
        $options = [
            CURLOPT_PROXY => 'http://ops:' . self::PROXY_PASSWORD . '@proxy.interno:3128',
            CURLOPT_PROXYUSERPWD => 'ops:' . self::PROXY_PASSWORD,
            CURLOPT_SSLKEYPASSWD => self::PROXY_PASSWORD,
        ];
        $curl = new CurlClient($options);
        $client = new RayaClient(Fixtures::TEST_KEY, ['http_client' => $curl]);
        RayaCash::setHttpClient($curl);
        $shown = self::dumps('curl', $curl) + self::dumps('clone', clone $curl)
            + self::dumps('client', $client) + self::globalDumps();
        self::assertNoSecrets($shown);
        foreach (['curl: print_r', 'curl: var_dump', 'clone: print_r'] as $how) {
            self::assertStringContainsString('CURLOPT_PROXYUSERPWD', $shown[$how], $how);
        }

        $message = 'Un CurlClient no se puede serializar (sus opciones pueden llevar contraseñas): '
            . 'creá uno en cada proceso.';
        self::assertThrows(\LogicException::class, static fn() => serialize($curl), $message);
        $payload = sprintf('O:%d:"%s":0:{}', strlen(CurlClient::class), CurlClient::class);
        self::assertThrows(\LogicException::class, static fn() => unserialize($payload), $message);

        // Una opción rechazada: el array entero, con la contraseña, es un argumento.
        self::assertSecretsStayOut(
            \ValueError::class,
            static fn(): mixed => new CurlClient($options + [CURLOPT_URL => 'https://x']),
            redacted: true,
        );
    }

    // --- serialize y clone de los objetos --------------------------------------------------------

    /**
     * Un objeto se serializa sin el cliente ni la respuesta, como pickle en Python: la clave del
     * cliente (o la de la llamada, en una lista) no viaja. Deserializado, el pago reembolsa con
     * el estilo global (sin clave global, falla antes de mandar nada) y la lista ya no sabe pedir
     * la página siguiente.
     */
    public function testSerializeDropsTheClientAndTheResponse(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        $this->api->add(200, Fixtures::transferPage(['t1'], 'c1'));
        $page = $client->transfers->list(['limit' => 1], ['api_key' => Fixtures::OTHER_KEY]);

        foreach (['payment' => $payment, 'page' => $page] as $label => $object) {
            self::assertNotNull($object->getLastResponse());
            $serialized = serialize($object);
            self::assertNoSecrets(["{$label}: serialize" => $serialized]);
            foreach (['raya_sk_live_', 'req_test', 'Requestor', 'RayaResponse', 'Token'] as $text) {
                self::assertStringNotContainsString($text, $serialized, $label);
            }
            $restored = unserialize($serialized);
            self::assertInstanceOf($object::class, $restored);
            self::assertNull($restored->getLastResponse());
            self::assertEquals($object->toArray(), $restored->toArray());
        }

        $restored = unserialize(serialize($payment));
        self::assertInstanceOf(Payment::class, $restored);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $restored->refund(),
            errorCode: 'API_KEY_MISSING',
        );
        $restored = unserialize(serialize($page));
        self::assertInstanceOf(ListObject::class, $restored);
        self::assertThrows(
            \LogicException::class,
            static fn() => $restored->nextPage(),
            "pedila vos con ['cursor' => \$page->next_cursor].",
        );
        self::assertCount(2, $this->api->requests);
    }

    /**
     * clone conserva el cliente y la respuesta, como copy en Python: el pago clonado reembolsa
     * con la clave de su cliente y la lista clonada pide la página siguiente con la de la
     * llamada. Y un clon no muestra más que el original.
     */
    public function testCloneKeepsTheClientAndTheResponse(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        $copy = clone $payment;
        self::assertSame($payment->getLastResponse(), $copy->getLastResponse());
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        self::assertSame('refunded', $copy->refund()->status);
        self::assertSame(
            ['POST', '/v1/payments/' . Fixtures::PAYMENT_ID . '/refund'],
            [$this->api->last()->method, $this->api->last()->path],
        );
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->authorization());

        $this->api->add(200, Fixtures::transferPage(['t1'], 'c1'));
        $page = $client->transfers->list(['limit' => 1], ['api_key' => Fixtures::OTHER_KEY]);
        $pageCopy = clone $page;
        $this->api->add(200, Fixtures::transferPage(['t2'], null));
        self::assertSame('t2', $pageCopy->nextPage()->data[0]->id);
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->authorization());
        self::assertSame(['limit' => '1', 'cursor' => 'c1'], $this->api->last()->query);
        self::assertCount(4, $this->api->requests);

        self::assertNoSecrets(self::dumps('copy', $copy) + self::dumps('pageCopy', $pageCopy));
    }

    // --- Ayudas ----------------------------------------------------------------------------------

    /**
     * Lo que lanza $call tiene que ser un $class, y ni sus volcados ni sus trazas (ni los de las
     * excepciones anteriores) pueden mostrar un secreto.
     *
     * 1. Con las trazas como en producción (sin argumentos): cada forma de volcar la excepción.
     * 2. Con las trazas como en desarrollo: getTraceAsString(), (string) y los argumentos de cada
     *    llamada (entrando en los objetos del SDK). En PHP 8.2+ siempre; en 8.1, solo si
     *    $everyVersion (la clave no pasó por un parámetro sensible). Con $redacted, alguno de
     *    los argumentos tiene que ser un SensitiveParameterValue.
     *
     * @template T of \Throwable
     * @param class-string<T> $class
     * @param \Closure(): mixed $call
     * @return T
     */
    private static function assertSecretsStayOut(
        string $class,
        \Closure $call,
        bool $redacted,
        bool $everyVersion = false,
    ): \Throwable {
        $error = self::thrownWith(self::PRODUCTION, $call);
        self::assertInstanceOf($class, $error);
        foreach (self::chain($error) as $index => $link) {
            self::assertFalse(self::hasArguments($link), 'zend.exception_ignore_args no anduvo.');
            self::assertNoSecrets(self::dumps("#{$index} " . $link::class, $link));
        }

        if (PHP_VERSION_ID >= 80200 || $everyVersion) {
            $traced = self::thrownWith(self::DEVELOPMENT, $call);
            self::assertInstanceOf($class, $traced);
            self::assertTrue(self::hasArguments($traced), 'La traza no tiene argumentos.');
            self::assertNoSecrets(self::traces($traced));
            if ($redacted) {
                self::assertTrue(
                    self::hasSensitiveArgument($traced),
                    "Ningún argumento de la traza es un SensitiveParameterValue:\n"
                    . $traced->getTraceAsString(),
                );
            }
        }
        return $error;
    }

    /**
     * Lo que lanza $call con esas directivas de php.ini (después las deja como estaban).
     *
     * @param array<string, string> $ini
     * @param \Closure(): mixed $call
     */
    private static function thrownWith(array $ini, \Closure $call): \Throwable
    {
        $before = [];
        foreach ($ini as $name => $value) {
            $previous = ini_set($name, $value);
            self::assertIsString($previous, "No pude cambiar {$name}.");
            $before[$name] = $previous;
        }
        try {
            return self::thrown($call);
        } finally {
            foreach ($before as $name => $value) {
                ini_set($name, $value);
            }
        }
    }

    /**
     * Todo lo que PHP muestra de $value, por forma de mirarlo: var_dump, print_r, var_export,
     * debug_zval_dump, json_encode, serialize (o su error, si no se deja), un (array) recorrido
     * entero y, si es Stringable, su texto.
     *
     * @return array<string, string>
     */
    private static function dumps(string $label, mixed $value): array
    {
        ob_start();
        var_dump($value);
        $dumped = (string) ob_get_clean();
        ob_start();
        debug_zval_dump($value);
        $zval = (string) ob_get_clean();
        try {
            $serialized = serialize($value);
        } catch (\Throwable $error) {
            $serialized = $error::class . ': ' . $error->getMessage();
        }
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        $shown = [
            "{$label}: var_dump" => $dumped,
            "{$label}: print_r" => print_r($value, true),
            "{$label}: var_export" => var_export($value, true),
            "{$label}: debug_zval_dump" => $zval,
            "{$label}: json_encode" => (string) $json,
            "{$label}: serialize" => $serialized,
            "{$label}: (array)" => self::walk($value),
        ];
        if ($value instanceof \Stringable) {
            $shown["{$label}: (string)"] = (string) $value;
        }
        return $shown;
    }

    /**
     * La traza de cada excepción de la cadena: como texto y los argumentos de cada llamada (uno
     * por llamada, así una falla dice cuál). Entra solo en los objetos del SDK: los de PHPUnit y
     * los de los tests no tienen la clave (y recorrerlos sería recorrer PHPUnit entero).
     *
     * @return array<string, string>
     */
    private static function traces(\Throwable $error): array
    {
        $sdk = static fn(object $object): bool => str_starts_with($object::class, 'RayaCash\\')
            && !str_starts_with($object::class, 'RayaCash\\Tests\\');
        $shown = [];
        foreach (self::chain($error) as $index => $link) {
            $shown["#{$index} getTraceAsString()"] = $link->getTraceAsString();
            $shown["#{$index} (string)"] = (string) $link;
            foreach ($link->getTrace() as $call => $frame) {
                $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
                $shown["#{$index} getTrace()[{$call}] {$function}()"]
                    = self::walk($frame['args'] ?? [], $sdk);
            }
        }
        return $shown;
    }

    /**
     * Los nombres y valores de $value: recorre los arrays y, con (array), cada objeto (también
     * sus propiedades privadas y protegidas), sin repetir objetos. Lo que ve un serializador
     * cualquiera. Con $enter, entra solo en los objetos que acepta.
     *
     * @param (\Closure(object): bool)|null $enter
     */
    private static function walk(mixed $value, ?\Closure $enter = null): string
    {
        $found = [];
        $seen = [];
        $pending = [$value];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (is_object($current)) {
                $id = spl_object_id($current);
                if (isset($seen[$id]) || ($enter !== null && !$enter($current))) {
                    continue;
                }
                $seen[$id] = true;
                $found[] = $current::class;
                $current = (array) $current;
            }
            if (is_array($current)) {
                foreach ($current as $key => $item) {
                    $found[] = (string) $key;
                    $pending[] = $item;
                }
            } elseif (is_scalar($current)) {
                $found[] = (string) $current;
            }
            if (count($found) > 1_000_000) {
                self::fail('El recorrido no termina: ¿un array con una referencia a sí mismo?');
            }
        }
        return implode("\n", $found);
    }

    /** @return list<\Throwable> La excepción y las anteriores (getPrevious()). */
    private static function chain(\Throwable $error): array
    {
        $chain = [];
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            $chain[] = $link;
        }
        return $chain;
    }

    /**
     * Si alguna llamada de la traza guarda sus argumentos. Las de include y require siempre
     * guardan el archivo (como el script de PHPUnit), aun con zend.exception_ignore_args=On.
     */
    private static function hasArguments(\Throwable $error): bool
    {
        $includes = ['include', 'include_once', 'require', 'require_once'];
        foreach ($error->getTrace() as $frame) {
            if (!in_array($frame['function'], $includes, true) && ($frame['args'] ?? []) !== []) {
                return true;
            }
        }
        return false;
    }

    private static function hasSensitiveArgument(\Throwable $error): bool
    {
        foreach (self::chain($error) as $link) {
            foreach ($link->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    // Por nombre: la clase no existe en PHP 8.1 (y PHPStan analiza desde 8.1).
                    $class = is_object($argument) ? $argument::class : '';
                    if (strcasecmp($class, 'SensitiveParameterValue') === 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Lo que muestra el estilo global: su estado (por Reflection: no hay getters de la clave) y
     * cada getter de RayaCash.
     *
     * @return array<string, string>
     */
    private static function globalDumps(): array
    {
        $state = (new \ReflectionClass(GlobalState::class))->getStaticProperties();
        $getters = [
            RayaCash::getApiBase(),
            RayaCash::getTimeout(),
            RayaCash::getMaxNetworkRetries(),
            RayaCash::getHttpClient(),
            RayaCash::getAppInfo(),
            RayaCash::getLog(),
            RayaCash::getLogger(),
        ];
        return self::dumps('GlobalState', $state) + self::dumps('RayaCash::get…()', $getters);
    }

    /**
     * Una copia de src/ en un directorio temporal, prefijada como lo hacen PHP-Scoper y Strauss:
     * las declaraciones de namespace, los use y los nombres completos (\RayaCash\…), no los
     * strings. Devuelve el directorio (borralo con removeTree()).
     */
    private static function prefixedCopy(string $prefix): string
    {
        $copy = sys_get_temp_dir() . '/rayacash-prefijado-' . bin2hex(random_bytes(6));
        $src = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $target = $copy . substr($file->getPathname(), strlen($src));
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }
            // Los nombres completos primero: después, «\RayaCash\» también estaría en los ya
            // prefijados.
            $code = str_replace(
                ['\\RayaCash\\', 'namespace RayaCash', 'use RayaCash\\'],
                ["\\{$prefix}RayaCash\\", "namespace {$prefix}RayaCash", "use {$prefix}RayaCash\\"],
                (string) file_get_contents($file->getPathname()),
            );
            file_put_contents($target, $code);
        }
        return $copy;
    }

    /** Borra un directorio con todo lo que tiene. */
    private static function removeTree(string $dir): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(\SplFileInfo::class, $entry);
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }

    /** El header Authorization de la última request. */
    private function authorization(): ?string
    {
        return $this->api->last()->header('authorization');
    }

    /**
     * Ningún texto muestra un secreto: el cuerpo de cada clave (enmascarada, se ven su prefijo y
     * sus últimos 4 caracteres) ni el secreto de los webhooks.
     *
     * @param array<string, string> $shown Por forma de mirarlo.
     */
    private static function assertNoSecrets(array $shown): void
    {
        self::assertNotSame([], $shown);
        $prefix = strlen('raya_sk_live_');
        $secrets = [
            'TEST_KEY' => substr(Fixtures::TEST_KEY, $prefix),
            'OTHER_KEY' => substr(Fixtures::OTHER_KEY, $prefix),
            'WEBHOOK_SECRET' => Fixtures::WEBHOOK_SECRET,
            'PROXY_PASSWORD' => self::PROXY_PASSWORD,
        ];
        foreach ($shown as $how => $text) {
            foreach ($secrets as $name => $secret) {
                self::assertStringNotContainsString($secret, $text, "{$how} muestra {$name}.");
            }
        }
    }
}
