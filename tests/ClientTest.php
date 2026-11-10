<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\Event;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\NotFoundException;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\Internal\Transport;
use RayaCash\ListObject;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\RayaClient;
use RayaCash\RayaObject;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\Loose;
use RayaCash\Tests\Support\MockApi;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Webhook;
use RayaCash\WebhookSignature;

/**
 * El cliente: de dónde sale la clave (argumento, opciones o entorno), sus opciones, los timeouts,
 * los headers, close(), rawRequest y constructEvent (test_client.py y client.test.ts).
 *
 * Los dos tests de asyncio de Python no tienen par (PHP es sincrónico) y el del context manager
 * pasa a close(). Que la clave no aparezca en var_dump, print_r y compañía lo mira SecurityTest.
 * Los testLoose* llaman al SDK como un proyecto sin strict_types (Support\Loose).
 */
final class ClientTest extends TestCase
{
    // Una tercera clave válida, para ver de qué fuente del entorno sale cada una.
    private const THIRD_KEY = 'raya_sk_live_' . 'TERCERA0123456789abcdefghijklmnopqrstuvwxyzABCDE';

    private const OPTIONS = 'las opciones son api_key, api_base, timeout, max_network_retries, '
        . 'http_client y app_info.';

    private const INVALID_BASE = 'api_base tiene que ser una URL https:// sin query ni '
        . 'credenciales (o http://localhost para desarrollo local).';

    private const LAST_PAGE = [
        ...Fixtures::TRANSFER_LIST,
        'has_more' => false,
        'next_cursor' => null,
    ];

    // --- La clave ------------------------------------------------------------------------------

    public function testKeyFromArgument(): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client(Fixtures::TEST_KEY)->balance->retrieve();
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $this->authorization());
    }

    /** Node: «la clave en el objeto de opciones». En PHP, también con config:. */
    public function testKeyInTheOptionsArray(): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->add(200, Fixtures::BALANCE);
        (new RayaClient(['api_key' => Fixtures::TEST_KEY, 'http_client' => $this->api]))
            ->balance->retrieve();
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $this->authorization());
        (new RayaClient(config: ['api_key' => Fixtures::OTHER_KEY, 'http_client' => $this->api]))
            ->balance->retrieve();
        self::assertSame(self::bearer(Fixtures::OTHER_KEY), $this->authorization());
    }

    /**
     * Python lo prueba con os.environ; en PHP, RAYA_SECRET_KEY puede estar en cualquiera de las
     * tres fuentes que lee el SDK: getenv() (putenv), $_ENV (Symfony Dotenv) o $_SERVER (los
     * env[] de FPM).
     */
    #[DataProvider('environmentSources')]
    public function testKeyFromEnvironment(string $source): void
    {
        self::setEnvironment($source, Fixtures::OTHER_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client(null)->balance->retrieve();
        self::assertSame(self::bearer(Fixtures::OTHER_KEY), $this->authorization());
    }

    /** @return iterable<string, array{string}> */
    public static function environmentSources(): iterable
    {
        yield 'getenv (putenv)' => ['getenv'];
        yield '$_ENV' => ['$_ENV'];
        yield '$_SERVER' => ['$_SERVER'];
    }

    /** PHP: $_ENV gana, después $_SERVER y por último getenv(); vacía cuenta como ausente. */
    public function testEnvironmentSourcesInOrder(): void
    {
        self::setEnvironment('getenv', self::THIRD_KEY);
        self::setEnvironment('$_SERVER', Fixtures::OTHER_KEY);
        self::setEnvironment('$_ENV', Fixtures::TEST_KEY);
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $this->retrieveWithEnvironmentKey());
        self::setEnvironment('$_ENV', '');
        self::assertSame(self::bearer(Fixtures::OTHER_KEY), $this->retrieveWithEnvironmentKey());
        self::setEnvironment('$_SERVER', '');
        self::assertSame(self::bearer(self::THIRD_KEY), $this->retrieveWithEnvironmentKey());
        // Vacía en las tres (os.environ.get(…) or None en Python): construye y falla al llamar.
        self::setEnvironment('getenv', '');
        $client = $this->api->client(null);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        self::assertCount(3, $this->api->requests);
    }

    /** Como en Python, el cliente lee el entorno una sola vez (al construirlo) y solo con null. */
    public function testEnvironmentKeyIsReadOnceAtConstruction(): void
    {
        self::setEnvironment('getenv', Fixtures::OTHER_KEY);
        $client = $this->api->client(null);
        self::setEnvironment('getenv', Fixtures::TEST_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve();
        self::assertSame(self::bearer(Fixtures::OTHER_KEY), $this->authorization());

        // Uno que se construyó sin clave no la busca después.
        self::setEnvironment('getenv', '');
        $keyless = $this->api->client(null);
        self::setEnvironment('getenv', Fixtures::TEST_KEY);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $keyless->balance->retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        // Y una clave vacía explícita no cae en la del entorno.
        self::assertThrows(
            AuthenticationException::class,
            static fn() => new RayaClient(''),
            errorCode: 'API_KEY_MISSING',
        );
        self::assertCount(1, $this->api->requests);
    }

    /** Una clave del entorno que nunca puede andar falla al construir, como en Python. */
    public function testInvalidEnvironmentKeyFailsAtConstruction(): void
    {
        self::setEnvironment('getenv', 'raya_pk_live_' . str_repeat('a', 48));
        self::assertThrows(
            AuthenticationException::class,
            static fn() => new RayaClient(),
            errorCode: 'API_KEY_PUBLISHABLE_NOT_ALLOWED',
        );
        self::setEnvironment('$_SERVER', '   ');
        self::assertThrows(
            AuthenticationException::class,
            static fn() => new RayaClient(),
            errorCode: 'API_KEY_MISSING',
        );
    }

    public function testMissingKeyFailsBeforeAnyRequest(): void
    {
        $client = $this->api->client(null);
        $error = self::assertThrows(
            AuthenticationException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        // Dice dónde ponerla, con los nombres de PHP (Python: RayaClient(api_key=…)).
        self::assertSame(
            'Falta la clave: pasá tu clave secreta (raya_sk_live_…) o el access_token de una '
            . 'cuenta conectada (raya_at_live_…) a new RayaClient(…), configurala con '
            . 'RayaCash::setApiKey() o definí la variable de entorno RAYA_SECRET_KEY.',
            $error->getMessage(),
        );
        self::assertSame([], $this->api->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidKeys(): iterable
    {
        yield 'vacía' => ['', 'API_KEY_MISSING'];
        yield 'solo espacios' => ['   ', 'API_KEY_MISSING'];
        yield 'la pública' => [
            'raya_pk_live_' . str_repeat('a', 48),
            'API_KEY_PUBLISHABLE_NOT_ALLOWED',
        ];
        yield 'con un espacio al final' => [Fixtures::TEST_KEY . ' ', 'API_KEY_INVALID'];
        yield 'con una ñ' => ['raya_sk_live_ñ', 'API_KEY_INVALID'];
        // Con \z y no $: en PHP, $ también acepta un \n al final.
        yield 'con un salto de línea al final' => [Fixtures::TEST_KEY . "\n", 'API_KEY_INVALID'];
        // PHP: un espacio Unicode (U+00A0) también es vacía, como en el strip() de Python.
        yield 'solo un espacio duro' => ["\xc2\xa0", 'API_KEY_MISSING'];
        // Los separadores U+001C a U+001F también son espacios para str.strip() (no para \s).
        yield 'solo un separador de archivo' => ["\x1c", 'API_KEY_MISSING'];
        // U+200B no es un espacio para Python: queda, y no es ASCII visible.
        yield 'solo un espacio de ancho cero' => ["\xe2\x80\x8b", 'API_KEY_INVALID'];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysFailAtConstruction(string $key, string $code): void
    {
        self::assertThrows(
            AuthenticationException::class,
            static fn() => new RayaClient($key),
            errorCode: $code,
        );
    }

    public function testKeyMustBeAString(): void
    {
        // Con strict_types, el TypeError es de PHP (el tipo del parámetro).
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => new RayaClient(123),
        );
        // En el array de opciones PHP no convierte nada: el TypeError es del SDK.
        $message = 'La clave secreta (o el access_token de una cuenta conectada) tiene que ser un '
            . 'string, no int.';
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => new RayaClient(['api_key' => 123]),
            $message,
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => new RayaClient(config: ['api_key' => 123]),
            $message,
        );
    }

    public function testPerRequestKey(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve(['api_key' => Fixtures::OTHER_KEY]);
        self::assertSame(self::bearer(Fixtures::OTHER_KEY), $this->authorization());
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $client->balance->retrieve(['api_key' => 'raya_pk_live_x']),
            errorCode: 'API_KEY_PUBLISHABLE_NOT_ALLOWED',
        );
        self::assertCount(1, $this->api->requests);

        // PHP: null no cuenta (usa la del cliente); vacía no cae en la del cliente.
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve(['api_key' => null]);
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $this->authorization());
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $client->balance->retrieve(['api_key' => '']),
            errorCode: 'API_KEY_MISSING',
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => $client->balance->retrieve(['api_key' => 123]),
            'La clave secreta (o el access_token de una cuenta conectada) tiene que ser un string, '
                . 'no int.',
        );
        self::assertCount(2, $this->api->requests);
    }

    /**
     * El access_token de una cuenta conectada (raya_at_live_…) es una clave más: viaja igual, se
     * valida igual y se enmascara igual. Lo que sigue sin valer es la clave pública.
     */
    public function testAccessTokenIsAKeyToo(): void
    {
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $this->api->add(200, Fixtures::USER);
        $connected->users->retrieve('me');
        self::assertSame(self::bearer(Fixtures::ACCESS_TOKEN), $this->authorization());
        self::assertSame(
            ['api_base' => 'https://api.raya.cash', 'api_key' => 'raya_at_live_…9hJk'],
            $connected->__debugInfo(),
        );
        // Por llamada también, y con un cliente armado con la clave secreta.
        $this->api->add(200, Fixtures::USER);
        $this->api->client()->users->retrieve('me', ['api_key' => Fixtures::ACCESS_TOKEN]);
        self::assertSame(self::bearer(Fixtures::ACCESS_TOKEN), $this->authorization());
        self::assertThrows(
            AuthenticationException::class,
            static fn() => new RayaClient(Fixtures::ACCESS_TOKEN . ' '),
            errorCode: 'API_KEY_INVALID',
        );
        self::assertCount(2, $this->api->requests);
    }

    /**
     * El access_token de la cuenta de prueba de una app (raya_at_test_…, el de Developers › Apps ›
     * tu app › Test) también: viaja igual, y la máscara conserva su prefijo y sus últimos 4.
     */
    public function testTestModeAccessTokenIsAKeyMaskedWithItsPrefix(): void
    {
        $connected = $this->api->client(Fixtures::TEST_MODE_ACCESS_TOKEN);
        $this->api->add(200, Fixtures::USER);
        $connected->users->retrieve('me');
        self::assertSame(self::bearer(Fixtures::TEST_MODE_ACCESS_TOKEN), $this->authorization());
        self::assertSame(
            ['api_base' => 'https://api.raya.cash', 'api_key' => 'raya_at_test_…L0sA'],
            $connected->__debugInfo(),
        );
        // Con 8 caracteres o menos después del prefijo, solo el prefijo (como mask_key).
        self::assertSame(
            'raya_at_test_…',
            (new RayaClient('raya_at_test_12345678'))->__debugInfo()['api_key'],
        );
    }

    public function testUnknownOptionsAreRejected(): void
    {
        $client = $this->api->client();
        // En Python, idempotency_key no es una opción (es un argumento aparte) y da TypeError. En
        // PHP es la opción de los POST: en un GET, ValueError (como el idempotencyKey de Node).
        self::assertThrows(
            \ValueError::class,
            static fn() => $client->balance->retrieve(['idempotency_key' => 'x']),
            'La Idempotency-Key solo va en los POST.',
        );
        // El nombre de Node, con la pista (como idempotency_key en Node).
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => $client->balance->retrieve(['idempotencyKey' => 'x']),
        );
        self::assertSame(
            'options no acepta "idempotencyKey" (¿quisiste decir idempotency_key?): las opciones '
            . 'son api_key, timeout, max_network_retries e idempotency_key (solo en los POST).',
            $error->getMessage(),
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => $client->balance->retrieve(['foo' => 1]),
            'options no acepta "foo": las opciones son api_key',
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => $client->balance->retrieve('x'),
        );
        self::assertSame([], $this->api->requests);
    }

    /** El repr de Python es __debugInfo() en PHP (lo que muestran var_dump y print_r). */
    public function testReprNeverShowsTheKey(): void
    {
        self::assertSame(
            ['api_base' => 'https://api.raya.cash', 'api_key' => 'raya_sk_live_…IJKL'],
            (new RayaClient(Fixtures::TEST_KEY))->__debugInfo(),
        );
        self::assertSame(
            ['api_base' => 'https://api.raya.cash', 'api_key' => null],
            (new RayaClient())->__debugInfo(),
        );
        self::assertSame(
            '…arga',
            (new RayaClient('otra_clave_cualquiera_larga'))->__debugInfo()['api_key'],
        );
        self::assertSame('…', (new RayaClient('corta'))->__debugInfo()['api_key']);
        // Con 8 caracteres o menos después del prefijo, solo el prefijo (como mask_key).
        self::assertSame(
            ['raya_sk_live_…', 'raya_sk_live_…6789'],
            [
                (new RayaClient('raya_sk_live_12345678'))->__debugInfo()['api_key'],
                (new RayaClient('raya_sk_live_123456789'))->__debugInfo()['api_key'],
            ],
        );
        // PHP: la api_base ya normalizada.
        self::assertSame(
            'http://localhost:8000',
            (new RayaClient(Fixtures::TEST_KEY, ['api_base' => 'http://localhost:8000/']))
                ->__debugInfo()['api_base'],
        );
    }

    // --- Las opciones del cliente ------------------------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function validApiBases(): iterable
    {
        yield 'con la barra final' => ['https://api.raya.cash/', 'https://api.raya.cash'];
        yield 'con una ruta' => [
            'https://proxy.example.com/raya',
            'https://proxy.example.com/raya',
        ];
        yield 'localhost' => ['http://localhost:8000', 'http://localhost:8000'];
        yield '127.0.0.1' => ['http://127.0.0.1:8000/', 'http://127.0.0.1:8000'];
        yield '[::1]' => ['http://[::1]:8000', 'http://[::1]:8000'];
        // PHP: como el strip() y el rstrip('/') de Python, y el host sin distinguir mayúsculas.
        yield 'con espacios alrededor' => ["  https://api.raya.cash/\n", 'https://api.raya.cash'];
        yield 'con varias barras finales' => ['https://api.raya.cash//', 'https://api.raya.cash'];
        yield 'LOCALHOST' => ['http://LOCALHOST:8000', 'http://LOCALHOST:8000'];
        // Una query o un fragmento vacíos cuentan como ausentes, y el ? o el # no quedan en la
        // api_base (las requests irían a /?/v1/… o a /), como en Python y Node.
        yield 'con query vacía' => ['https://api.raya.cash?', 'https://api.raya.cash'];
        yield 'con fragmento vacío' => ['https://api.raya.cash#', 'https://api.raya.cash'];
        yield 'con la barra, la query y el fragmento vacíos' => [
            'https://api.raya.cash/?#',
            'https://api.raya.cash',
        ];
        yield 'localhost con una ruta y query vacía' => [
            'http://localhost:8000/raya/?',
            'http://localhost:8000/raya',
        ];
    }

    #[DataProvider('validApiBases')]
    public function testValidApiBase(string $base, string $expected): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client(Fixtures::TEST_KEY, ['api_base' => $base])->balance->retrieve();
        self::assertSame($expected . '/v1/balance', $this->api->last()->url);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidApiBases(): iterable
    {
        yield 'http fuera de localhost' => ['http://api.raya.cash'];
        yield 'ftp' => ['ftp://x'];
        yield 'sin esquema' => ['api.raya.cash'];
        yield 'sin host' => ['https://'];
        yield 'con query' => ['https://x?y=1'];
        yield 'con credenciales' => ['https://u:p@x'];
        // Node
        yield 'con fragmento' => ['https://x#frag'];
        // PHP
        yield 'vacía' => [''];
        yield 'con usuario' => ['https://u@x'];
        // Una contraseña sin usuario también son credenciales (el parts.password de Python).
        yield 'con contraseña y sin usuario' => ['https://:p@x'];
        yield 'un host que empieza con localhost' => ['http://localhost.example.com'];
        yield 'con un espacio' => ['https://api.raya .cash'];
        yield 'con un host que no es ASCII' => ['https://ñandú.example'];
    }

    #[DataProvider('invalidApiBases')]
    public function testInvalidApiBase(string $base): void
    {
        $error = self::assertThrows(
            \ValueError::class,
            static fn() => new RayaClient(Fixtures::TEST_KEY, ['api_base' => $base]),
        );
        self::assertSame(self::INVALID_BASE, $error->getMessage());
    }

    public function testApiBaseMustBeAString(): void
    {
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => new RayaClient(Fixtures::TEST_KEY, ['api_base' => 1]),
            'api_base tiene que ser un string, no int.',
        );
    }

    /**
     * Las 13 filas de Python, más las de Node (el tope del timeout y las opciones que no existen)
     * y algunas de PHP. Python mira solo la clase; acá también el mensaje.
     *
     * @return iterable<string, array{array<string, mixed>, class-string<\Throwable>, string}>
     */
    public static function invalidClientOptions(): iterable
    {
        $positive = 'timeout tiene que ser un número de segundos mayor que 0.';
        yield 'timeout 0' => [['timeout' => 0], \ValueError::class, $positive];
        yield 'timeout infinito' => [['timeout' => INF], \ValueError::class, $positive];
        yield 'timeout NAN' => [['timeout' => NAN], \ValueError::class, $positive];
        yield 'timeout negativo' => [['timeout' => -1], \ValueError::class, $positive];
        // Node: 2 ** 31 ms. curl lo recibe en ms en un long, que en Windows es de 32 bits.
        yield 'timeout de más de 24 días' => [
            ['timeout' => 2147484],
            \ValueError::class,
            'timeout no puede pasar de 2147483 segundos (unos 24 días).',
        ];
        yield 'timeout string' => [
            ['timeout' => '60'],
            \TypeError::class,
            'timeout tiene que ser un número de segundos, como 65, no string.',
        ];
        // En un array de opciones nada se convierte: el error nombra el tipo que llegó (el de
        // RayaCash::setTimeout() no, porque sin strict_types el bool puede venir de un texto).
        yield 'timeout bool' => [
            ['timeout' => true],
            \TypeError::class,
            'timeout tiene que ser un número de segundos, como 65, no bool.',
        ];
        yield 'max_network_retries negativo' => [
            ['max_network_retries' => -1],
            \ValueError::class,
            'max_network_retries no puede ser negativo.',
        ];
        $int = 'max_network_retries tiene que ser un int.';
        yield 'max_network_retries float' => [
            ['max_network_retries' => 1.5],
            \TypeError::class,
            $int,
        ];
        yield 'max_network_retries bool' => [
            ['max_network_retries' => true],
            \TypeError::class,
            $int,
        ];
        yield 'http_client que no es un HttpClientInterface' => [
            ['http_client' => new \stdClass()],
            \TypeError::class,
            'http_client tiene que implementar RayaCash\HttpClient\HttpClientInterface (como '
                . 'CurlClient), no stdClass.',
        ];
        // Node: una función en el lugar del cliente.
        yield 'http_client que es una función' => [
            ['http_client' => static fn(): mixed => null],
            \TypeError::class,
            'http_client tiene que implementar RayaCash\HttpClient\HttpClientInterface (como '
                . 'CurlClient), no Closure.',
        ];
        // Python: async_http_client con un cliente sync. En PHP no existe (como fetch en Node).
        yield 'async_http_client' => [
            ['async_http_client' => new MockApi()],
            \TypeError::class,
            'RayaClient no acepta la opción "async_http_client": ' . self::OPTIONS,
        ];
        yield 'fetch' => [
            ['fetch' => new \stdClass()],
            \TypeError::class,
            'RayaClient no acepta la opción "fetch": ' . self::OPTIONS,
        ];
        yield 'httpClient' => [
            ['httpClient' => new MockApi()],
            \TypeError::class,
            'RayaClient no acepta la opción "httpClient" (¿quisiste decir http_client?): '
                . self::OPTIONS,
        ];
        yield 'app_info sin name' => [
            ['app_info' => ['name' => '']],
            \ValueError::class,
            'app_info necesita un name.',
        ];
        // PHP: [] no es una lista con valores (es el {} de Python): le falta el name.
        yield 'app_info vacío' => [
            ['app_info' => []],
            \ValueError::class,
            'app_info necesita un name.',
        ];
        yield 'app_info con un name que no es string' => [
            ['app_info' => ['name' => 1]],
            \ValueError::class,
            'app_info necesita un name.',
        ];
        yield 'app_info con otra clave' => [
            ['app_info' => ['name' => 'x', 'otra' => 'y']],
            \ValueError::class,
            "app_info no acepta 'otra': usá name, version y url.",
        ];
        // Con varias, nombra la primera en orden alfabético (sorted() en Python).
        yield 'app_info con dos claves de más' => [
            ['app_info' => ['name' => 'x', 'zeta' => 1, 'alfa' => 1]],
            \ValueError::class,
            "app_info no acepta 'alfa': usá name, version y url.",
        ];
        yield 'app_info con una version que no es string' => [
            ['app_info' => ['name' => 'x', 'version' => 1]],
            \TypeError::class,
            "app_info['version'] tiene que ser un string.",
        ];
        yield 'app_info con un salto de línea' => [
            ['app_info' => ['name' => "plugin\r\nX-Inyectado: 1"]],
            \ValueError::class,
            'app_info solo admite caracteres ASCII visibles: va en el header User-Agent.',
        ];
        yield 'app_info string' => [
            ['app_info' => 'x'],
            \TypeError::class,
            "app_info tiene que ser un array como ['name' => 'mi-plugin', 'version' => '1.2.0'].",
        ];
        // PHP: una lista no es un dict en Python ni un objeto en Node (los dos dan TypeError).
        yield 'app_info lista' => [
            ['app_info' => ['mi-plugin', '1.2.0']],
            \TypeError::class,
            "app_info tiene que ser un array como ['name' => 'mi-plugin', 'version' => '1.2.0'].",
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('invalidClientOptions')]
    public function testInvalidClientOptions(array $config, string $class, string $message): void
    {
        $error = self::thrown(
            // @phpstan-ignore argument.type (opciones inválidas a propósito)
            static fn() => new RayaClient(Fixtures::TEST_KEY, $config),
        );
        self::assertSame($class, $error::class, $error->getMessage());
        self::assertSame($message, $error->getMessage());
    }

    /** Node: «la forma de los argumentos del constructor». */
    public function testConstructorArgumentShapes(): void
    {
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => new RayaClient(['api_key' => Fixtures::TEST_KEY], ['timeout' => 5]),
        );
        self::assertSame(
            "Pasá las opciones en un solo array: new RayaClient(['api_key' => …, "
            . "'timeout' => …]).",
            $error->getMessage(),
        );
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => new RayaClient(Fixtures::TEST_KEY, ['api_key' => Fixtures::TEST_KEY]),
        );
        self::assertSame(
            'Pasaste la clave dos veces: como primer argumento y en api_key.',
            $error->getMessage(),
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => new RayaClient(Fixtures::TEST_KEY, 'x'),
        );
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => new RayaClient(['apiKey' => Fixtures::TEST_KEY]),
        );
        self::assertSame(
            'RayaClient no acepta la opción "apiKey" (¿quisiste decir api_key?): '
            . self::OPTIONS,
            $error->getMessage(),
        );
        // La clave (o una opción) en una lista, en cualquier argumento: TypeError (en Python y
        // Node también), sin la clave en el mensaje.
        $lists = [
            '[$clave]' => static fn() => new RayaClient([Fixtures::TEST_KEY]),
            'config: [$clave]' => static fn() => new RayaClient(config: [Fixtures::TEST_KEY]),
            '(null, [$clave])' => static fn() => new RayaClient(null, [Fixtures::TEST_KEY]),
            '($clave, [90])' => static fn() => new RayaClient(Fixtures::TEST_KEY, [90]),
        ];
        foreach ($lists as $form => $build) {
            $error = self::assertThrows(\TypeError::class, $build);
            self::assertSame(
                "RayaClient recibe la clave como string, new RayaClient('raya_sk_live_…'), o las "
                . "opciones en un array con nombres, como ['api_key' => …].",
                $error->getMessage(),
                $form,
            );
            self::assertStringNotContainsString(Fixtures::TEST_KEY, $error->getMessage());
        }
        // Un array vacío no es una lista con la clave: son las opciones de fábrica, como {} en
        // Node (la clave sale de RAYA_SECRET_KEY).
        putenv('RAYA_SECRET_KEY=' . Fixtures::TEST_KEY);
        self::assertSame(
            ['api_base' => 'https://api.raya.cash', 'api_key' => 'raya_sk_live_…IJKL'],
            (new RayaClient([]))->__debugInfo(),
        );
        // Node rechaza ({ apiKey }, {}). En PHP, [] es el valor por defecto del segundo argumento:
        // no se distingue de no pasarlo y no se pierde ninguna opción, así que vale.
        $this->api->add(200, Fixtures::BALANCE);
        (new RayaClient(['api_key' => Fixtures::TEST_KEY, 'http_client' => $this->api], []))
            ->balance->retrieve();
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $this->authorization());
    }

    /**
     * PHP: gana el primer chequeo que falla, en el orden de Python (las opciones desconocidas,
     * que en Python frena el propio lenguaje, van antes que todo).
     */
    public function testConstructorChecksInPythonsOrder(): void
    {
        $bad = [
            'http_client' => new \stdClass(),
            'api_base' => 'ftp://x',
            'timeout' => 0,
            'max_network_retries' => -1,
            'app_info' => 'x',
        ];
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (opciones inválidas a propósito)
            static fn() => new RayaClient('raya_pk_live_x', [...$bad, 'apiBase' => 'x']),
            'RayaClient no acepta la opción "apiBase" (¿quisiste decir api_base?)',
        );
        self::assertThrows(
            AuthenticationException::class,
            // @phpstan-ignore argument.type (opciones inválidas a propósito)
            static fn() => new RayaClient('raya_pk_live_x', $bad),
            errorCode: 'API_KEY_PUBLISHABLE_NOT_ALLOWED',
        );
        $fixes = [
            'http_client' => [\TypeError::class, 'http_client tiene que implementar', $this->api],
            'api_base' => [\ValueError::class, self::INVALID_BASE, 'http://localhost:8000'],
            'timeout' => [\ValueError::class, 'mayor que 0', 5],
            'max_network_retries' => [\ValueError::class, 'no puede ser negativo', 0],
            'app_info' => [\TypeError::class, 'app_info tiene que ser un array', ['name' => 'x']],
        ];
        foreach ($fixes as $option => [$class, $message, $fix]) {
            $error = self::thrown(
                // @phpstan-ignore argument.type (opciones inválidas a propósito)
                static fn() => new RayaClient(Fixtures::TEST_KEY, $bad),
            );
            self::assertSame($class, $error::class, $error->getMessage());
            self::assertStringContainsString($message, $error->getMessage());
            $bad[$option] = $fix;
        }
        $this->api->add(200, Fixtures::BALANCE);
        // @phpstan-ignore argument.type (PHPStan no sigue los cambios del foreach)
        (new RayaClient(Fixtures::TEST_KEY, $bad))->balance->retrieve();
        self::assertSame('http://localhost:8000/v1/balance', $this->api->last()->url);
    }

    /**
     * Lo mismo en cada request, antes de mandar nada, en el orden de _prepare en Python: el
     * método, la ruta, la clave, el body (un GET con body, como en Node), la Idempotency-Key, el
     * timeout y los reintentos. Cada llamada arregla lo que falló en la anterior.
     */
    public function testRequestChecksInPythonsOrder(): void
    {
        $keyless = $this->api->client(null);
        $client = $this->api->client();
        $body = ['a' => 1];
        $all = ['idempotency_key' => 'k', 'timeout' => 0, 'max_network_retries' => -1];
        $calls = [
            [
                static fn() => $keyless->rawRequest('put', 'v1/x', body: $body, options: $all),
                \ValueError::class,
                'La API solo acepta GET y POST.',
            ],
            [
                static fn() => $keyless->rawRequest('get', 'v1/x', body: $body, options: $all),
                \ValueError::class,
                'La ruta tiene que empezar con /, como /v1/balance.',
            ],
            [
                static fn() => $keyless->rawRequest('get', '/v1/x?y', body: $body, options: $all),
                \ValueError::class,
                'La ruta va sin query (usá params) y con los ids codificados con rawurlencode().',
            ],
            [
                static fn() => $keyless->rawRequest('get', '/v1/x', body: $body, options: $all),
                AuthenticationException::class,
                'Falta la clave: pasá tu clave secreta',
            ],
            [
                static fn() => $client->rawRequest('get', '/v1/x', body: $body, options: $all),
                \ValueError::class,
                'Un GET no lleva body: mandá los filtros en params.',
            ],
            [
                static fn() => $client->rawRequest('get', '/v1/x', options: $all),
                \ValueError::class,
                'La Idempotency-Key solo va en los POST.',
            ],
            [
                static fn() => $client->rawRequest(
                    'get',
                    '/v1/x',
                    options: ['timeout' => 0, 'max_network_retries' => -1],
                ),
                \ValueError::class,
                'timeout tiene que ser un número de segundos mayor que 0.',
            ],
            [
                static fn() => $client->rawRequest(
                    'get',
                    '/v1/x',
                    options: ['timeout' => 5, 'max_network_retries' => -1],
                ),
                \ValueError::class,
                'max_network_retries no puede ser negativo.',
            ],
            // En un POST: el body, la Idempotency-Key y recién después el timeout.
            [
                static fn() => $client->rawRequest(
                    'post',
                    '/v1/x',
                    body: ['x' => NAN],
                    options: ['idempotency_key' => ' ', 'timeout' => 0],
                ),
                \ValueError::class,
                'No se puede mandar NAN en JSON.',
            ],
            [
                static fn() => $client->rawRequest(
                    'post',
                    '/v1/x',
                    options: ['idempotency_key' => ' ', 'timeout' => 0],
                ),
                \ValueError::class,
                'idempotency_key tiene que tener de 1 a 255 caracteres ASCII visibles',
            ],
        ];
        foreach ($calls as $i => [$call, $class, $message]) {
            $error = self::thrown($call);
            self::assertSame($class, $error::class, "llamada {$i}: {$error->getMessage()}");
            self::assertStringStartsWith($message, $error->getMessage(), "llamada {$i}");
        }
        self::assertSame([], $this->api->requests);
        $this->api->add(200, ['object' => 'nuevo']);
        $client->rawRequest('get', '/v1/x', options: ['timeout' => 5, 'max_network_retries' => 0]);
        self::assertSame(['GET', '/v1/x'], $this->lastMethodAndPath());
    }

    /** PHP: una opción en null usa el valor por defecto, como None en Python. */
    public function testNullOptionsUseTheDefaults(): void
    {
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => null,
            'timeout' => null,
            'max_network_retries' => null,
            'http_client' => $this->api,
            'app_info' => null,
        ]);
        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve();
        self::assertSame('https://api.raya.cash/v1/balance', $this->api->last()->url);
        self::assertSame([65.0, 10.0], $this->lastTimeouts());
        self::assertMatchesRegularExpression('/ curl\/\S+\z/', $this->userAgent());
        self::assertCount(3, $this->api->requests); // los 2 reintentos de fábrica
        self::assertSame([0.5, 1.0], $this->sleeps);

        // En una llamada, también: usa la del cliente (en Python, un timeout o unos reintentos en
        // None son TypeError; acá, como en Node). Y una Idempotency-Key en null no es una
        // Idempotency-Key: un GET no falla.
        $fixed = $this->api->client(
            Fixtures::TEST_KEY,
            ['timeout' => 7, 'max_network_retries' => 0],
        );
        $this->api->addError(500, 'INTERNAL_ERROR');
        self::assertThrows(
            ApiException::class,
            static fn() => $fixed->rawRequest('get', '/v1/balance', options: [
                'timeout' => null,
                'max_network_retries' => null,
                'idempotency_key' => null,
            ]),
            errorCode: 'INTERNAL_ERROR',
        );
        self::assertSame([7.0, 7.0], $this->lastTimeouts());
        self::assertNull($this->api->last()->header('idempotency-key'));
        self::assertCount(4, $this->api->requests); // sin reintentos: los 0 del cliente
        self::assertSame([0.5, 1.0], $this->sleeps);
    }

    public function testTimeouts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->api->add(200, Fixtures::BALANCE);
        }
        $client = $this->api->client();
        $client->balance->retrieve();
        self::assertSame([65.0, 10.0], $this->lastTimeouts());
        $client->balance->retrieve(['timeout' => 5]);
        self::assertSame([5.0, 5.0], $this->lastTimeouts());
        // Python pasa un httpx.Timeout(90.0). Acá son segundos: para conectar, el menor entre 10
        // y el timeout.
        $this->api->client(Fixtures::TEST_KEY, ['timeout' => 90])->balance->retrieve();
        self::assertSame([90.0, 10.0], $this->lastTimeouts());
        // PHP: también fracciones de segundo.
        $this->api->client(Fixtures::TEST_KEY, ['timeout' => 0.25])->balance->retrieve();
        self::assertSame([0.25, 0.25], $this->lastTimeouts());
        // Y hasta 2147483 s, el tope (uno más falla: invalidClientOptions()).
        $this->api->client(Fixtures::TEST_KEY, ['timeout' => 2147483])->balance->retrieve();
        self::assertSame([2147483.0, 10.0], $this->lastTimeouts());
    }

    public function testDefaultHeaders(): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client()->balance->retrieve();
        $request = $this->api->last();
        self::assertSame('application/json', $request->header('accept'));
        self::assertMatchesRegularExpression(
            '/\Arayacash-php\/\d+\.\d+\.\d+ PHP\/\d+\.\d+\.\d+ curl\/\S+\z/',
            $this->userAgent(),
        );
        self::assertNull($request->header('content-type'));
        self::assertNull($request->header('idempotency-key'));
        // PHP: un GET lleva estos tres y nada más (ningún X-…).
        self::assertSame(
            ['Authorization', 'Accept', 'User-Agent'],
            array_keys($request->headers()),
        );
    }

    public function testAppInfoGoesInTheUserAgent(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->api->add(200, Fixtures::BALANCE);
        }
        $info = ['name' => 'mi-plugin', 'version' => '1.2.0', 'url' => 'https://plugin.example'];
        $this->api->client(Fixtures::TEST_KEY, ['app_info' => $info])->balance->retrieve();
        self::assertStringEndsWith(' mi-plugin/1.2.0 (https://plugin.example)', $this->userAgent());
        $this->api->client(Fixtures::TEST_KEY, ['app_info' => ['name' => 'solo-nombre']])
            ->balance->retrieve();
        self::assertStringEndsWith(' solo-nombre', $this->userAgent());
        // PHP: version y url en null no cuentan.
        $this->api->client(
            Fixtures::TEST_KEY,
            ['app_info' => ['name' => 'x', 'version' => null, 'url' => null]],
        )->balance->retrieve();
        self::assertMatchesRegularExpression('/ curl\/\S+ x\z/', $this->userAgent());
        // Vacías tampoco: ni «x/» ni «x ()» (el if version: de Python).
        $this->api->client(
            Fixtures::TEST_KEY,
            ['app_info' => ['name' => 'x', 'version' => '', 'url' => '']],
        )->balance->retrieve();
        self::assertMatchesRegularExpression('/ curl\/\S+ x\z/', $this->userAgent());
    }

    /** PHP: el cliente nunca lee el estilo global (RayaCash::set…()), solo sus opciones. */
    public function testClientIgnoresTheGlobalStyle(): void
    {
        $global = new MockApi();
        RayaCash::setApiKey(Fixtures::OTHER_KEY);
        RayaCash::setApiBase('http://localhost:8000');
        RayaCash::setTimeout(5);
        RayaCash::setMaxNetworkRetries(0);
        RayaCash::setAppInfo('plugin-global', '9.9.9');
        RayaCash::setHttpClient($global);

        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client()->balance->retrieve();
        $request = $this->api->last();
        self::assertSame('https://api.raya.cash/v1/balance', $request->url);
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $request->header('authorization'));
        self::assertSame([65.0, 10.0], $this->lastTimeouts());
        self::assertStringNotContainsString('plugin-global', $this->userAgent());
        // Reintentó una vez: 2 reintentos de fábrica, no los 0 globales.
        self::assertCount(2, $this->api->requests);
        self::assertSame([0.5], $this->sleeps);
        self::assertSame([], $global->requests);

        // Sin clave propia, tampoco usa la global: busca RAYA_SECRET_KEY (que acá no está).
        $keyless = $this->api->client(null);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $keyless->balance->retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
    }

    /**
     * ← test_context_manager_closes_only_owned_clients. PHP no tiene context manager: close()
     * suelta el cliente HTTP propio (la próxima request crea otro) y nunca toca el que pasaste.
     */
    public function testCloseReleasesOnlyOwnedClients(): void
    {
        $created = 0;
        Transport::$factory = function () use (&$created): HttpClientInterface {
            $created++;
            return $this->api;
        };
        $client = new RayaClient(Fixtures::TEST_KEY);
        $client->close(); // antes de la primera request no hay nada que soltar
        self::assertSame(0, $created);
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve();
        $client->balance->retrieve();
        self::assertSame(1, $created); // uno solo, con la primera request, y se reusa
        $client->close();
        $client->close(); // se puede llamar más de una vez
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve(); // y el cliente sigue usable: abre otro
        self::assertSame(2, $created);

        $yours = new MockApi();
        $withYours = new RayaClient(Fixtures::TEST_KEY, ['http_client' => $yours]);
        $yours->add(200, Fixtures::BALANCE);
        $yours->add(200, Fixtures::BALANCE);
        $withYours->balance->retrieve();
        $withYours->close();
        $withYours->balance->retrieve(); // sigue usando el tuyo
        self::assertCount(2, $yours->requests);
        self::assertSame(2, $created);
        self::assertCount(3, $this->api->requests);
    }

    // --- rawRequest y constructEvent -------------------------------------------------------

    public function testRawRequest(): void
    {
        $client = $this->api->client();
        $this->api->add(200, ['object' => 'nuevo', 'x' => 1]);
        $obj = $client->rawRequest('get', '/v1/algo', params: ['limit' => 5, 'skip' => null]);
        self::assertSame(RayaObject::class, $obj::class);
        self::assertSame(1, $obj['x']);
        self::assertSame('GET', $this->api->last()->method);
        self::assertSame('https://api.raya.cash/v1/algo?limit=5', $this->api->last()->url);

        $this->api->add(201, Fixtures::PAYMENT_REFUNDED);
        $payment = $client->rawRequest('post', '/v1/algo', body: ['a' => 1]);
        self::assertSame(Payment::class, $payment::class);
        self::assertSame(['a' => 1], $this->api->lastJson());
        self::assertSame('application/json', $this->api->last()->header('content-type'));
        $response = $payment->getLastResponse();
        self::assertNotNull($response);
        self::assertSame($this->api->last()->header('idempotency-key'), $response->idempotencyKey);
        // Sin clave propia, un UUID v4 generado para esta llamada.
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $response->idempotencyKey ?? '',
        );
    }

    /**
     * PHP: el body va siempre como objeto JSON ({} aunque sea un array vacío, sin los null) y un
     * POST sin body va sin Content-Type, con la Idempotency-Key de las opciones.
     */
    public function testRawRequestPostBodies(): void
    {
        $client = $this->api->client();
        foreach ([[], new \stdClass(), ['a' => null]] as $body) {
            $this->api->add(200, ['object' => 'nuevo']);
            $client->rawRequest('post', '/v1/algo', body: $body);
            self::assertSame('{}', $this->api->last()->body);
            self::assertSame('application/json', $this->api->last()->header('content-type'));
        }
        $this->api->add(200, ['object' => 'nuevo']);
        $client->rawRequest('post', '/v1/algo', options: ['idempotency_key' => 'k-1']);
        $request = $this->api->last();
        self::assertNull($request->body);
        self::assertNull($request->header('content-type'));
        self::assertSame('k-1', $request->header('idempotency-key'));
    }

    /**
     * ← test_raw_request_async_lists_can_paginate (en PHP no hay async). Node: «las listas de
     * rawRequest se pueden paginar».
     */
    public function testRawRequestListsCanPaginate(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::TRANSFER_LIST);
        $page = $client->rawRequest('get', '/v1/transfers', params: ['limit' => 2]);
        self::assertInstanceOf(ListObject::class, $page);
        $this->api->add(200, self::LAST_PAGE);
        $page->nextPage();
        self::assertSame('/v1/transfers', $this->api->last()->path);
        self::assertSame(
            ['limit' => '2', 'cursor' => Fixtures::NEXT_CURSOR],
            $this->api->last()->query,
        );

        // PHP: la página siguiente va con las mismas opciones (también la clave por llamada).
        $this->api->add(200, Fixtures::TRANSFER_LIST);
        $page = $client->rawRequest('get', '/v1/transfers', options: [
            'api_key' => Fixtures::OTHER_KEY,
            'timeout' => 5,
        ]);
        self::assertInstanceOf(ListObject::class, $page);
        $this->api->add(200, self::LAST_PAGE);
        $page->nextPage();
        self::assertSame(self::bearer(Fixtures::OTHER_KEY), $this->authorization());
        self::assertSame([5.0, 5.0], $this->lastTimeouts());

        // Una lista que vino de un POST no sabe pedir la siguiente.
        $this->api->add(200, Fixtures::TRANSFER_LIST);
        $posted = $client->rawRequest('post', '/v1/transfers/buscar');
        self::assertInstanceOf(ListObject::class, $posted);
        self::assertThrows(
            \LogicException::class,
            static fn() => $posted->nextPage(),
            'no vino de un método list',
        );
        self::assertCount(5, $this->api->requests);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     string,
     *     array<string, mixed>|null,
     *     array<string, mixed>|null,
     *     array{idempotency_key?: string},
     *     string,
     * }>
     */
    public static function invalidRawRequests(): iterable
    {
        yield 'PUT' => ['put', '/v1/balance', null, null, [], 'La API solo acepta GET y POST.'];
        yield 'sin la barra inicial' => [
            'get',
            'v1/balance',
            null,
            null,
            [],
            'La ruta tiene que empezar con /, como /v1/balance.',
        ];
        yield 'idempotency_key en un GET' => [
            'get',
            '/v1/balance',
            null,
            null,
            ['idempotency_key' => 'x'],
            'La Idempotency-Key solo va en los POST.',
        ];
        // Node: un GET con body.
        yield 'GET con body' => [
            'get',
            '/v1/balance',
            null,
            ['a' => 1],
            [],
            'Un GET no lleva body: mandá los filtros en params.',
        ];
        // La ruta va sin query ni fragmento, como en Python y Node.
        $path = 'La ruta va sin query (usá params) y con los ids codificados con rawurlencode().';
        yield 'con query' => ['get', '/v1/transfers?limit=1', null, null, [], $path];
        yield 'con fragmento' => ['get', '/v1/transfers#x', null, null, [], $path];
        // Ni \ ni caracteres de control, como en Python y Node (fetch convierte \ en / y saca los
        // saltos y los tabs). El id con \ va codificado: %5C.
        yield 'con \\' => ['get', '/v1/transfers/a\\b', null, null, [], $path];
        yield 'con un tab' => ['get', "/v1/transfers/a\tb", null, null, [], $path];
        // PHP: y en ASCII visible, con los ids codificados (Python y Node codifican el resto).
        yield 'con un espacio' => ['get', '/v1/transfers/a b', null, null, [], $path];
        yield 'con una ñ' => ['get', '/v1/transfers/ñ', null, null, [], $path];
    }

    /**
     * @param array<string, mixed>|null $params
     * @param array<string, mixed>|null $body
     * @param array{idempotency_key?: string} $options
     */
    #[DataProvider('invalidRawRequests')]
    public function testRawRequestValidation(
        string $method,
        string $path,
        ?array $params,
        ?array $body,
        array $options,
        string $message,
    ): void {
        $client = $this->api->client();
        $error = self::assertThrows(
            \ValueError::class,
            static fn() => $client->rawRequest($method, $path, $params, $body, $options),
        );
        self::assertSame($message, $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /** Node: «rawRequest valida params y body». Lo que allá mira checkParams, acá es de PHP. */
    public function testRawRequestValidatesParamsAndBody(): void
    {
        $client = $this->api->client();
        $error = self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (una lista, a propósito)
            static fn() => $client->rawRequest('get', '/v1/x', params: [1]),
        );
        self::assertSame(
            'params tiene que ser un array con los nombres de los filtros, no una lista: va en la '
            . 'query.',
            $error->getMessage(),
        );
        $error = self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (una lista, a propósito)
            static fn() => $client->rawRequest('post', '/v1/x', body: [1]),
        );
        self::assertSame(
            'body tiene que ser un array con los nombres de los campos (o un stdClass), no una '
            . 'lista: va como JSON en el POST.',
            $error->getMessage(),
        );
        // Las opciones tampoco (Python: «options tiene que ser un dict»), y la clave no se ve.
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => $client->rawRequest('get', '/v1/x', options: [Fixtures::OTHER_KEY]),
        );
        self::assertSame(
            "options tiene que ser un array con nombres, como ['timeout' => 90], no una lista.",
            $error->getMessage(),
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => $client->rawRequest('get', '/v1/x', params: ['flag' => true]),
            'flag no puede ser un bool.',
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => $client->rawRequest('get', '/v1/x', params: 'a=1'),
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => $client->rawRequest(1, '/v1/x'),
        );
        $error = self::thrown(
            // @phpstan-ignore argument.unknown (lo que haría alguien sin PHPStan)
            static fn() => $client->rawRequest('get', '/v1/x', query: []),
        );
        self::assertSame(\Error::class, $error::class);
        self::assertSame('Unknown named parameter $query', $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    public function testConstructEventBindsTheClient(): void
    {
        $client = $this->api->client();
        $body = json_encode(Fixtures::EVENT_PAYMENT_SUCCEEDED, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
        $event = $client->constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
        $payment = $event->data->object;
        self::assertInstanceOf(Payment::class, $payment);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $refunded = $payment->refund();
        self::assertSame('refunded', $refunded->status);
        self::assertSame('/v1/payments/' . $payment->id . '/refund', $this->api->last()->path);
        // PHP: con la clave de este cliente.
        self::assertSame(self::bearer(Fixtures::TEST_KEY), $this->authorization());
    }

    // --- Sin strict_types (Support\Loose) --------------------------------------------------

    /**
     * Sin strict_types, PHP convierte la clave antes de entrar. El false de un getenv() sin la
     * variable queda en '': falla sin mandar nada, al construir (como un '' en Python) o, con el
     * estilo global, en la request. Un número viaja como texto y lo rechaza la API (Python y
     * strict_types: TypeError).
     */
    public function testLooseKeyOfAnotherType(): void
    {
        self::assertThrows(
            AuthenticationException::class,
            static fn() => self::looseClient(false),
            errorCode: 'API_KEY_MISSING',
        );
        RayaCash::setHttpClient($this->api);
        Loose::call([RayaCash::class, 'setApiKey'], false);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => Balance::retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        self::assertSame([], $this->api->requests);

        $client = self::looseClient(123, ['http_client' => $this->api]);
        $this->api->addError(401, 'API_KEY_INVALID');
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'API_KEY_INVALID',
        );
        self::assertSame('Bearer 123', $this->authorization());
        self::assertCount(1, $this->api->requests);
    }

    /**
     * Los setters aceptan un número en texto (el valor de un .env): '90' es 90. Los arrays de
     * opciones nunca se convierten, con o sin strict_types: ahí '90' es un TypeError.
     */
    public function testLooseNumericStringsOnlyInTheSetters(): void
    {
        Loose::call([RayaCash::class, 'setTimeout'], '90');
        Loose::call([RayaCash::class, 'setMaxNetworkRetries'], '0');
        self::assertSame(90, RayaCash::getTimeout());
        self::assertSame(0, RayaCash::getMaxNetworkRetries());
        Loose::call([RayaCash::class, 'setTimeout'], '90.5');
        self::assertSame(90.5, RayaCash::getTimeout());
        // Un texto que no es un número solo se puede convertir en bool (true), y el setter
        // rechaza los bool. Por eso su mensaje no nombra el tipo, como el de Python.
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => Loose::call([RayaCash::class, 'setTimeout'], '90s'),
        );
        self::assertSame(
            'timeout tiene que ser un número de segundos, como 65.',
            $error->getMessage(),
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => self::looseClient(Fixtures::TEST_KEY, ['timeout' => '90']),
            'timeout tiene que ser un número de segundos, como 65, no string.',
        );
        $client = $this->api->client();
        self::assertThrows(
            \TypeError::class,
            static fn() => Loose::call(
                [$client->balance, 'retrieve'],
                ['max_network_retries' => '1'],
            ),
            'max_network_retries tiene que ser un int.',
        );
        self::assertSame([], $this->api->requests);
    }

    /**
     * true y false no son un timeout ni una cantidad de reintentos: TypeError, como en Python y
     * Node. Los setters aceptan bool en la firma justamente para rechazarlos: si no, PHP los
     * convertiría en 1 y 0 (un timeout de 1 s o los reintentos apagados, sin ningún error). En
     * los arrays de opciones nada se convierte: el mismo TypeError. El de setTimeout() no nombra
     * el tipo, como el de Python: sin strict_types, el bool pudo ser un texto.
     *
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function looseBools(): iterable
    {
        $timeout = 'timeout tiene que ser un número de segundos, como 65.';
        $retries = 'max_network_retries tiene que ser un int.';
        foreach ([true, false] as $value) {
            $shown = var_export($value, true);
            yield "setTimeout({$shown})" => [
                static fn(): mixed => Loose::call(RayaCash::setTimeout(...), $value),
                $timeout,
            ];
            yield "setMaxNetworkRetries({$shown})" => [
                static fn(): mixed => Loose::call(RayaCash::setMaxNetworkRetries(...), $value),
                $retries,
            ];
        }
        yield "['max_network_retries' => true]" => [
            static fn(): mixed => self::looseClient(
                Fixtures::TEST_KEY,
                ['max_network_retries' => true],
            ),
            $retries,
        ];
    }

    /** @param \Closure(): mixed $set */
    #[DataProvider('looseBools')]
    public function testLooseBoolsAreNotTimeoutsOrRetries(\Closure $set, string $message): void
    {
        $error = self::assertThrows(\TypeError::class, $set);
        self::assertSame($message, $error->getMessage());
        self::assertSame(RayaCash::DEFAULT_TIMEOUT, RayaCash::getTimeout());
        self::assertSame(
            RayaCash::DEFAULT_MAX_NETWORK_RETRIES,
            RayaCash::getMaxNetworkRetries(),
        );
    }

    /**
     * Un número con decimales no es una cantidad de reintentos: TypeError, como en Python y Node.
     * setMaxNetworkRetries() acepta float en la firma justamente para rechazarlo: si no, PHP
     * convertiría 1.5 en 1 y 0.5 en 0 (sin reintentos), con un aviso que producción no muestra.
     * '2' sigue siendo 2 (testLooseNumericStringsOnlyInTheSetters).
     *
     * @return iterable<string, array{float|string}>
     */
    public static function looseFractions(): iterable
    {
        yield '1.5' => [1.5];
        yield "'1.5'" => ['1.5'];
        yield '0.5' => [0.5];
    }

    #[DataProvider('looseFractions')]
    public function testLooseFractionsAreNotRetries(float|string $retries): void
    {
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => Loose::call(RayaCash::setMaxNetworkRetries(...), $retries),
        );
        self::assertSame('max_network_retries tiene que ser un int.', $error->getMessage());
        self::assertSame(
            RayaCash::DEFAULT_MAX_NETWORK_RETRIES,
            RayaCash::getMaxNetworkRetries(),
        );
    }

    /**
     * Los ids se vuelven texto: 123 es "123" y true es "1" (los dos llegan a la API, que no los
     * encuentra). false es '' y falla antes de mandar nada; null nunca se convierte.
     */
    public function testLooseIdsBecomeStrings(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        Loose::call([$client->payments, 'refund'], 123);
        self::assertSame(['POST', '/v1/payments/123/refund'], $this->lastMethodAndPath());
        $this->api->addError(404, 'PAYMENT_NOT_FOUND');
        self::assertThrows(
            NotFoundException::class,
            static fn() => Loose::call([$client->payments, 'retrieve'], true),
        );
        self::assertSame(['GET', '/v1/payments/1'], $this->lastMethodAndPath());
        self::assertThrows(
            \ValueError::class,
            static fn() => Loose::call([$client->transfers, 'retrieve'], false),
            'transferId no puede estar vacío.',
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => Loose::call([$client->transfers, 'retrieve'], null),
        );
        self::assertCount(2, $this->api->requests);
    }

    /**
     * Sin strict_types, PHP convertiría una tolerancia false en 0, que apaga el chequeo del reloj
     * y deja pasar una firma de hace una hora. Por eso el parámetro es mixed: false, true o '300'
     * son un TypeError, como en Python y Node, en los tres métodos que la reciben.
     */
    public function testLooseToleranceThatIsNotANumberIsATypeError(): void
    {
        $client = $this->api->client();
        $body = Fixtures::WEBHOOK_TEST_BODY;
        $header = 't=' . Fixtures::WEBHOOK_TEST_TIMESTAMP . ',v1=' . Fixtures::WEBHOOK_SIGNATURE;
        $secret = Fixtures::WEBHOOK_SECRET;
        $this->now = Fixtures::WEBHOOK_TEST_TIMESTAMP + 3600.0; // firmado hace una hora
        self::assertThrows(
            SignatureVerificationException::class,
            static fn() => $client->constructEvent($body, $header, $secret),
            'está a más de 300 s de tu reloj',
        );
        // Closures de cada método: Loose::call() los llama desde su archivo, sin strict_types.
        $methods = [
            '$client->constructEvent' => $client->constructEvent(...),
            'Webhook::constructEvent' => Webhook::constructEvent(...),
            'WebhookSignature::verifyHeader' => WebhookSignature::verifyHeader(...),
        ];
        foreach ($methods as $name => $method) {
            foreach ([false, true, '300'] as $tolerance) {
                $error = self::assertThrows(
                    \TypeError::class,
                    static fn() => Loose::call($method, $body, $header, $secret, $tolerance),
                );
                self::assertSame(
                    'tolerance tiene que ser un número de segundos o null.',
                    $error->getMessage(),
                    $name . '(…, ' . var_export($tolerance, true) . ')',
                );
            }
        }
        // null y 0 la apagan, también sin strict_types.
        foreach ([null, 0] as $off) {
            $event = Loose::call([$client, 'constructEvent'], $body, $header, $secret, $off);
            self::assertInstanceOf(Event::class, $event);
            self::assertSame('webhook.test', $event->type);
        }
        self::assertSame([], $this->api->requests);
    }

    // --- Ayudas ----------------------------------------------------------------------------

    private static function bearer(string $key): string
    {
        return 'Bearer ' . $key;
    }

    private function authorization(): ?string
    {
        return $this->api->last()->header('authorization');
    }

    private function userAgent(): string
    {
        return $this->api->last()->header('user-agent') ?? '';
    }

    /** @return array{float, float} El timeout y el de conexión de la última request. */
    private function lastTimeouts(): array
    {
        $request = $this->api->last();
        return [$request->timeout, $request->connectTimeout];
    }

    /** @return array{string, string} */
    private function lastMethodAndPath(): array
    {
        return [$this->api->last()->method, $this->api->last()->path];
    }

    /** Pone RAYA_SECRET_KEY en una sola de las tres fuentes que lee el SDK. */
    private static function setEnvironment(string $source, string $value): void
    {
        if ($source === 'getenv') {
            putenv('RAYA_SECRET_KEY=' . $value);
        } elseif ($source === '$_ENV') {
            $_ENV['RAYA_SECRET_KEY'] = $value;
        } else {
            $_SERVER['RAYA_SECRET_KEY'] = $value;
        }
    }

    /** Un cliente sin clave propia (lee el entorno), con la Authorization que mandó. */
    private function retrieveWithEnvironmentKey(): ?string
    {
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client(null)->balance->retrieve();
        return $this->authorization();
    }

    /**
     * new RayaClient(...$arguments) desde un archivo sin strict_types. new no es un callable: lo
     * hace ReflectionClass::newInstance, que llama al constructor convirtiendo los escalares.
     */
    private static function looseClient(mixed ...$arguments): RayaClient
    {
        $newInstance = [new \ReflectionClass(RayaClient::class), 'newInstance'];
        $client = Loose::call($newInstance, ...$arguments);
        self::assertInstanceOf(RayaClient::class, $client);
        return $client;
    }
}
