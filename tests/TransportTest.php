<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\ApiException;
use RayaCash\HttpClient\CurlClient;
use RayaCash\HttpClient\TransportException;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\RayaClient;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\LoopbackServer;
use RayaCash\Tests\Support\TestCase;

/**
 * El CurlClient de verdad contra un servidor en 127.0.0.1 (Support\LoopbackServer): lo que MockApi
 * no puede probar porque lo pone curl (Content-Length, Expect, redirects, cortes y timeouts de
 * verdad). Es transport.test.ts de Node (Python no tiene este archivo), más los casos propios de
 * curl. Nunca sale de loopback: la guardia de los tests sigue puesta y los proxies de
 * phpunit.xml.dist mandan cualquier otro host a 127.0.0.1:9, donde no hay nada.
 *
 * Cada test que lo usa arranca su propio servidor y tearDown() lo frena.
 *
 * @phpstan-import-type Received from LoopbackServer
 */
final class TransportTest extends TestCase
{
    private const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-'
        . '[0-9a-f]{12}\z/';

    private const USER_AGENT = '/\Arayacash-php\/\d+\.\d+\.\d+ PHP\/\d+\.\d+\.\d+ curl\/\S+\z/';

    private const POST_HINT
        = ' Reintentá con la misma Idempotency-Key: nunca se ejecuta dos veces.';

    private ?LoopbackServer $server = null;

    protected function tearDown(): void
    {
        try {
            $this->server?->stop();
        } finally {
            $this->server = null;
            parent::tearDown();
        }
    }

    /** Node: «un POST con body lleva Content-Type y Content-Length». */
    public function testPostWithBodySendsContentTypeAndContentLength(): void
    {
        $payment = $this->client('json-201-PAYMENT_PENDING')->payments->create(
            ['amount' => '15000.50', 'description' => 'ñandú'],
            ['idempotency_key' => 'k-1'],
        );
        self::assertSame(Payment::class, $payment::class);
        self::assertSame('req_loopback', $payment->getLastResponse()?->requestId);

        [$request] = $this->requests(1);
        self::assertSame('POST', $request['method']);
        self::assertSame('/v1/payments', $request['path']);
        // La ñ y la ú van como escapes JSON (barra invertida, u00f1 y u00fa): 50 bytes. La barra
        // se arma con chr(92), así el archivo no depende de cómo se escriba.
        $escape = chr(92) . 'u00';
        $body = '{"amount":15000.5,"description":"' . $escape . 'f1and' . $escape . 'fa"}';
        self::assertSame(50, strlen($body));
        self::assertSame($body, $request['body']);
        self::assertSame('application/json', $request['headers']['content-type'] ?? null);
        self::assertSame('50', $request['headers']['content-length'] ?? null);
        self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
        self::assertArrayNotHasKey('expect', $request['headers']); // PHP: ver largeBodies()
        self::assertSame('k-1', $request['headers']['idempotency-key'] ?? null);
        self::assertSame(
            'Bearer ' . Fixtures::TEST_KEY,
            $request['headers']['authorization'] ?? null,
        );
        self::assertSame('application/json', $request['headers']['accept'] ?? null);
        self::assertMatchesRegularExpression(
            self::USER_AGENT,
            $request['headers']['user-agent'] ?? '',
        );

        // PHP: el User-Agent exacto (sin el sufijo de la distribución que puede traer
        // PHP_VERSION) y ningún otro header: ni Expect, ni Transfer-Encoding, ni X-….
        $curl = curl_version();
        $curlVersion = is_array($curl) ? $curl['version'] ?? null : null;
        self::assertIsString($curlVersion);
        self::assertSame(
            sprintf(
                'rayacash-php/%s PHP/%d.%d.%d curl/%s',
                RayaCash::VERSION,
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
                PHP_RELEASE_VERSION,
                $curlVersion,
            ),
            $request['headers']['user-agent'] ?? null,
        );
        self::assertSame("127.0.0.1:{$this->server()->port}", $request['headers']['host'] ?? null);
        $names = array_keys($request['headers']);
        sort($names);
        self::assertSame(
            [
                'accept',
                'accept-encoding',
                'authorization',
                'content-length',
                'content-type',
                'host',
                'idempotency-key',
                'user-agent',
            ],
            $names,
        );
    }

    /** Node: «el reembolso va sin body: Content-Length 0 y sin Content-Type». */
    public function testRefundSendsNoBody(): void
    {
        $payment = $this->client('json-200-PAYMENT_REFUNDED')->payments->refund(
            Fixtures::PAYMENT_ID,
        );
        self::assertSame('refunded', $payment->status);

        [$request] = $this->requests(1);
        self::assertSame('POST', $request['method']);
        self::assertSame('/v1/payments/' . Fixtures::PAYMENT_ID . '/refund', $request['path']);
        self::assertSame('', $request['body']);
        self::assertSame('0', $request['headers']['content-length'] ?? null);
        self::assertArrayNotHasKey('content-type', $request['headers']);
        self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
        // PHP: sin clave propia, va la que generó el SDK para esta llamada.
        self::assertMatchesRegularExpression(
            self::UUID_V4,
            $request['headers']['idempotency-key'] ?? '',
        );
    }

    /** Node: «un GET va sin body ni Content-Type». */
    public function testGetSendsNoBodyNorContentType(): void
    {
        $balance = $this->client('json-200-BALANCE')->balance->retrieve();
        self::assertSame(152340.5, $balance->available);

        [$request] = $this->requests(1);
        self::assertSame('GET', $request['method']);
        self::assertSame('/v1/balance', $request['path']);
        self::assertArrayNotHasKey('content-type', $request['headers']);
        self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        // PHP: tampoco Content-Length (no hay body que medir).
        self::assertSame('', $request['body']);
        self::assertArrayNotHasKey('content-length', $request['headers']);
        self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
    }

    /**
     * Node: «un 301 no se sigue». El Location apunta al mismo servidor: si curl lo siguiera,
     * llegaría una segunda request.
     */
    public function testRedirectIsNotFollowed(): void
    {
        $client = $this->client('redirect', retries: 2);
        $error = self::assertThrows(
            ApiException::class,
            static fn() => $client->balance->retrieve(),
        );
        self::assertSame(ApiException::class, $error::class);
        self::assertSame(301, $error->getHttpStatus());
        self::assertCount(1, $this->requests(1));
        // PHP: un 3xx sin el objeto error es INVALID_RESPONSE, con sus headers, y no se reintenta.
        self::assertSame('INVALID_RESPONSE', $error->getErrorCode());
        self::assertSame(
            "http://127.0.0.1:{$this->server()->port}/v1/otra",
            $error->getHttpHeaders()->get('Location'),
        );
        self::assertSame([], $this->sleeps);
    }

    /** Node: «un corte a mitad de la respuesta es NETWORK_ERROR y se reintenta». */
    public function testCutMidResponseIsNetworkErrorAndRetried(): void
    {
        // El primer intento llega cortado (Content-Length 100, 14 bytes de body) y el reintento,
        // entero: el body se lee dentro del intento, así un corte es un error de conexión.
        $balance = $this->client('cut-once', retries: 1)->balance->retrieve();
        self::assertSame(152340.5, $balance->available);
        self::assertCount(2, $this->requests(2));
        self::assertSame([0.5], $this->sleeps);

        $client = $this->client('cut');
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertTrue($error->shouldRetry());
        // PHP: es el 18 de curl (PARTIAL_FILE), con la TransportException como causa.
        self::assertStringStartsWith(
            'No pudimos conectarnos con la API de Raya (curl 18: ',
            $error->getMessage(),
        );
        $cause = $error->getPrevious();
        self::assertInstanceOf(TransportException::class, $cause);
        self::assertTrue($cause->isRetryable());
        self::assertFalse($cause->isTimeout());
        $this->requests(1);
    }

    /** Node: «un servidor que no responde es TIMEOUT». */
    public function testServerThatNeverAnswersIsTimeout(): void
    {
        // Node: 200 ms. Acá, segundos. Es el errno 28 de curl aunque la máquina esté cargada: el
        // servidor nunca contesta.
        $client = $this->client('hang', timeout: 0.2);
        $started = hrtime(true);
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'TIMEOUT',
        );
        $elapsed = ((int) hrtime(true) - (int) $started) / 1e9;
        self::assertTrue($error->shouldRetry());
        // PHP: el mensaje de Python, la causa de curl y el tiempo que esperó (no los 65 s de
        // fábrica).
        self::assertSame('La API de Raya no respondió a tiempo.', $error->getMessage());
        $cause = $error->getPrevious();
        self::assertInstanceOf(TransportException::class, $cause);
        self::assertTrue($cause->isTimeout());
        self::assertStringStartsWith('curl 28: ', $cause->getDetail());
        self::assertGreaterThanOrEqual(0.15, $elapsed);
        self::assertLessThan(5.0, $elapsed);
        // La request llegó: lo que no llegó fue la respuesta.
        $this->requests(1);
    }

    /** Node: «un puerto cerrado es NETWORK_ERROR». */
    public function testClosedPortIsNetworkError(): void
    {
        $port = self::closedPort();
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => "http://127.0.0.1:{$port}",
            'http_client' => new CurlClient(),
            'max_network_retries' => 0,
            'timeout' => 5,
        ]);
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertTrue($error->shouldRetry());
        // Node: /ECONNREFUSED/. En curl es el 7 (COULDNT_CONNECT), y nombra el puerto.
        self::assertStringContainsString('(curl 7: ', $error->getMessage());
        self::assertStringContainsString("127.0.0.1 port {$port}", $error->getMessage());
    }

    /**
     * Node: «la guardia no deja salir de loopback» (1 de 2). En los tests, el cliente HTTP propio
     * del SDK es la guardia del bootstrap: falla antes de abrir un socket, en los dos estilos.
     * No es una TransportException: el SDK no la envuelve ni la reintenta.
     */
    public function testDefaultTransportIsTheNetworkGuard(): void
    {
        $client = new RayaClient(Fixtures::TEST_KEY); // sin http_client: el CurlClient propio
        $error = self::thrown(static fn() => $client->balance->retrieve());
        self::assertSame(\RuntimeException::class, $error::class);
        self::assertSame(self::NO_NETWORK, $error->getMessage());

        RayaCash::setApiKey(Fixtures::TEST_KEY);
        $error = self::thrown(static fn() => Balance::retrieve());
        self::assertSame(\RuntimeException::class, $error::class);
        self::assertSame(self::NO_NETWORK, $error->getMessage());
        self::assertSame([], $this->sleeps);
    }

    /**
     * Node: «la guardia no deja salir de loopback» (2 de 2). Un CurlClient de verdad hacia otro
     * host: libcurl lo manda al proxy de phpunit.xml.dist (127.0.0.1:9), donde no hay nada, y
     * falla con COULDNT_CONNECT. Sin el proxy sería «Could not resolve host» (curl 6): .invalid
     * nunca resuelve, así que ni con esa capa rota llega a ningún lado.
     */
    public function testRealCurlToAnotherHostHitsTheGuardProxy(): void
    {
        self::assertSame('http://127.0.0.1:9', getenv('https_proxy'));
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => 'https://api.raya.cash.invalid',
            'http_client' => new CurlClient(),
            'max_network_retries' => 0,
            'timeout' => 5,
        ]);
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertStringContainsString('(curl 7: ', $error->getMessage());
        self::assertStringContainsString('127.0.0.1 port 9', $error->getMessage());
    }

    /**
     * PHP: libcurl agrega «Expect: 100-continue» a los POST grandes (según la versión, desde 1 KB
     * o desde 1 MB) y espera un segundo a que el servidor conteste. El SDK lo saca.
     *
     * @return iterable<string, array{int}>
     */
    public static function largeBodies(): iterable
    {
        yield '2 KB' => [2048];
        yield '1,1 MB' => [1100000];
    }

    #[DataProvider('largeBodies')]
    public function testLargePostSendsNoExpectContinue(int $size): void
    {
        $text = str_repeat('a', $size);
        $this->client('json-200-BALANCE')->rawRequest(
            'post',
            '/v1/algo_nuevo',
            body: ['texto' => $text],
            options: ['idempotency_key' => 'k-1'],
        );
        [$request] = $this->requests(1);
        $body = '{"texto":"' . $text . '"}';
        self::assertSame(['POST', '/v1/algo_nuevo'], [$request['method'], $request['path']]);
        self::assertArrayNotHasKey('expect', $request['headers']);
        self::assertArrayNotHasKey('transfer-encoding', $request['headers']);
        self::assertSame((string) strlen($body), $request['headers']['content-length'] ?? null);
        self::assertSame([strlen($body), hash('sha256', $body)], [
            $request['length'],
            $request['sha256'],
        ]);
    }

    /**
     * PHP: un «100 Continue» antes de la respuesta se descarta. Trae su propio Raya-Request-Id:
     * si sus headers se mezclaran con los de la respuesta, saldría «req_interim, req_loopback».
     */
    public function testInterimResponsesAreDiscarded(): void
    {
        $balance = $this->client('continue')->balance->retrieve();
        self::assertSame(152340.5, $balance->available);
        $response = $balance->getLastResponse();
        self::assertNotNull($response);
        self::assertSame(200, $response->httpStatus);
        self::assertSame('req_loopback', $response->requestId);
        self::assertNull($response->headers->get('X-Interim'));
        $this->requests(1);
    }

    /**
     * PHP: los headers de la respuesta se leen sin importar las mayúsculas (HTTP/2 los manda en
     * minúscula), y uno repetido junta sus valores.
     */
    public function testResponseHeadersAreReadInAnyCase(): void
    {
        $balance = $this->client('lowercase')->balance->retrieve();
        $response = $balance->getLastResponse();
        self::assertNotNull($response);
        self::assertSame('req_loopback', $response->requestId);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('uno, dos', $response->headers->get('X-Raya-Dup'));
        $this->requests(1);
    }

    /**
     * PHP: el servidor cierra sin responder (curl 52, «Empty reply»): NETWORK_ERROR, y se reintenta
     * con la misma Idempotency-Key en cada intento.
     */
    public function testConnectionClosedWithoutResponseIsRetried(): void
    {
        $client = $this->client('close', retries: 2);
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->payments->create(['amount' => 15000]),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertTrue($error->shouldRetry());
        self::assertStringStartsWith(
            'No pudimos conectarnos con la API de Raya (curl 52: ',
            $error->getMessage(),
        );
        self::assertStringEndsWith(self::POST_HINT, $error->getMessage());
        self::assertSame([0.5, 1.0], $this->sleeps);
        $key = $error->getIdempotencyKey();
        self::assertMatchesRegularExpression(self::UUID_V4, $key ?? '');
        $keys = array_map(
            static fn(array $request): ?string => $request['headers']['idempotency-key'] ?? null,
            $this->requests(3),
        );
        self::assertSame([$key, $key, $key], $keys);
    }

    /**
     * PHP: un error de curl que no es de red (acá, una interfaz que no existe: el 45) no se
     * reintenta, porque daría lo mismo, y la request nunca sale.
     */
    public function testCurlErrorsThatAreNotNetworkErrorsAreNotRetried(): void
    {
        $client = $this->client(
            'json-200-BALANCE',
            retries: 2,
            curl: new CurlClient([CURLOPT_INTERFACE => 'if!nope0']),
        );
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertFalse($error->shouldRetry());
        self::assertStringContainsString('(curl 45: ', $error->getMessage());
        self::assertSame([], $this->sleeps);
        self::assertSame([], $this->server()->received());
    }

    /**
     * PHP: un CurlClient reusa su handle (curl_reset entre requests): nada de una request pasa a
     * la siguiente, y después de close() sigue andando.
     */
    public function testOneCurlClientSendsManyRequests(): void
    {
        $curl = new CurlClient();
        $this->client('json-201-PAYMENT_PENDING', curl: $curl)->payments->create(
            ['amount' => 15000, 'description' => 'Pedido #1042'],
            ['idempotency_key' => 'k-1'],
        );
        $this->client('json-200-BALANCE', curl: $curl)->balance->retrieve();
        $this->client('json-200-PAYMENT_REFUNDED', curl: $curl)->payments->refund(
            Fixtures::PAYMENT_ID,
            ['idempotency_key' => 'k-2'],
        );
        $curl->close();
        $balance = $this->client('json-200-BALANCE', curl: $curl)->balance->retrieve();
        self::assertSame(152340.5, $balance->available);

        [$create, $get, $refund, $again] = $this->requests(4);
        self::assertSame('POST', $create['method']);
        self::assertSame('{"amount":15000,"description":"Pedido #1042"}', $create['body']);
        self::assertSame('application/json', $create['headers']['content-type'] ?? null);
        self::assertSame('k-1', $create['headers']['idempotency-key'] ?? null);
        foreach ([$get, $again] as $request) {
            self::assertSame(['GET', '/v1/balance', ''], [
                $request['method'],
                $request['path'],
                $request['body'],
            ]);
            self::assertArrayNotHasKey('content-type', $request['headers']);
            self::assertArrayNotHasKey('content-length', $request['headers']);
            self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        }
        self::assertSame(['POST', ''], [$refund['method'], $refund['body']]);
        self::assertSame('0', $refund['headers']['content-length'] ?? null);
        self::assertArrayNotHasKey('content-type', $refund['headers']);
        self::assertSame('k-2', $refund['headers']['idempotency-key'] ?? null);
    }

    /**
     * PHP: los ids y la query llegan como los codificó el SDK. curl resuelve los «/../» de una
     * ruta, pero no toca un ..%2F ni un %2B.
     */
    public function testIdsAndQueryTravelEncoded(): void
    {
        $this->client('json-200-TRANSFER_INBOUND')->transfers->retrieve('../balance');
        $this->client('json-200-TRANSFER_LIST')->transfers->list([
            'direction' => 'inbound',
            'created_gte' => '2026-10-23T00:00:00+00:00',
        ]);
        [$retrieve, $list] = $this->requests(2);
        self::assertSame('/v1/transfers/..%2Fbalance', $retrieve['path']);
        self::assertSame(
            '/v1/transfers?direction=inbound&created_gte=2026-10-23T00%3A00%3A00%2B00%3A00',
            $list['path'],
        );
    }

    /**
     * PHP: habla HTTP/1.1, como httpx y undici, y acepta respuestas comprimidas: curl las
     * descomprime antes de que el SDK las lea.
     */
    public function testSpeaksHttp11AndAcceptsCompressedResponses(): void
    {
        $balance = $this->client('gzip')->balance->retrieve();
        self::assertSame(152340.5, $balance->available);
        self::assertSame(
            '{"object":"balance","available":152340.5,"currency":"ARS"}',
            $balance->getLastResponse()?->body,
        );
        [$request] = $this->requests(1);
        self::assertSame('HTTP/1.1', $request['protocol']);
        self::assertStringContainsString('gzip', $request['headers']['accept-encoding'] ?? '');
    }

    /**
     * PHP: tus opciones de curl valen en cada request (después de cada curl_reset) y pueden
     * cambiar los valores por defecto del SDK, como la versión de HTTP.
     */
    public function testYourCurlOptionsApplyToEveryRequest(): void
    {
        $curl = new CurlClient([
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
            CURLOPT_ENCODING => 'identity',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $client = $this->client('json-200-BALANCE', curl: $curl);
        $client->balance->retrieve();
        $client->balance->retrieve();
        foreach ($this->requests(2) as $request) {
            self::assertSame('HTTP/1.0', $request['protocol']);
            self::assertSame('identity', $request['headers']['accept-encoding'] ?? null);
        }
    }

    /**
     * PHP: cada request verifica el certificado de la API y su nombre, aunque no le pases nada a
     * CurlClient (send() fuerza CURLOPT_SSL_VERIFYPEER y CURLOPT_SSL_VERIFYHOST). Con un
     * certificado en el que curl no confía, la conexión se corta antes de que salga la request,
     * con la clave: curl 60, que se reintenta, como un error de TLS de httpx o undici. Con el
     * certificado en CURLOPT_CAINFO, la misma request anda; para otro nombre, no.
     */
    public function testEveryRequestVerifiesTheCertificate(): void
    {
        $server = $this->server = LoopbackServer::start(tls: true);
        $error = self::assertThrows(
            ApiConnectionException::class,
            fn() => $this->client('json-200-BALANCE', retries: 1)->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertTrue($error->shouldRetry());
        self::assertStringStartsWith(
            'No pudimos conectarnos con la API de Raya (curl 60: ',
            $error->getMessage(),
        );
        self::assertSame([0.5], $this->sleeps);
        self::assertSame([], $server->received());

        self::assertNotNull($server->certificate);
        $trusted = new CurlClient([CURLOPT_CAINFO => $server->certificate]);
        $balance = $this->client('json-200-BALANCE', curl: $trusted)->balance->retrieve();
        self::assertSame(152340.5, $balance->available);
        [$request] = $this->requests(1);
        self::assertSame(
            'Bearer ' . Fixtures::TEST_KEY,
            $request['headers']['authorization'] ?? null,
        );

        // El certificado es de 127.0.0.1, no de localhost (aunque sea el mismo servidor).
        $base = str_replace('//127.0.0.1:', '//localhost:', $server->base('json-200-BALANCE'));
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => $base,
            'http_client' => $trusted,
            'max_network_retries' => 0,
            'timeout' => 5,
        ]);
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertStringStartsWith(
            'No pudimos conectarnos con la API de Raya (curl 60: ',
            $error->getMessage(),
        );
        self::assertSame([], $server->received());
    }

    /** @return iterable<string, array{mixed}> */
    public static function verifyHostOn(): iterable
    {
        yield 'true' => [true];
        // Un valor leído de un .env o de la config.
        yield "'2'" => ['2'];
    }

    /**
     * PHP: CurlClient acepta CURLOPT_SSL_VERIFYHOST en true o '2' (verificar) y los guarda como
     * 2, así no salta el aviso de curl_setopt() con true (PHP lo convierte en 1, que libcurl ya no
     * acepta), ni al armarlo ni en cada request. curl tomaría '2' sin aviso: es el SDK el que lo
     * deja como 2 en vez de rechazarlo. En Laravel, un aviso es una excepción: acá se juntan los
     * avisos en el test, sin depender del failOnNotice de phpunit.xml.dist.
     */
    #[DataProvider('verifyHostOn')]
    public function testCurlClientAcceptsVerifyHostTrue(mixed $value): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;
            return true;
        });
        try {
            $curl = new CurlClient([CURLOPT_SSL_VERIFYHOST => $value]);
            $balance = $this->client('json-200-BALANCE', curl: $curl)->balance->retrieve();
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $notices);
        self::assertSame(152340.5, $balance->available);
        $this->requests(1);
    }

    /**
     * PHP: CurlClient no acepta las opciones que maneja el SDK, apagar TLS ni las que muestran los
     * headers de cada request (con la clave), y falla al armarlo, no en la primera request.
     *
     * @return iterable<string, array{array<int, mixed>, string}>
     */
    public static function refusedCurlOptions(): iterable
    {
        $managed = [
            'CURLOPT_URL',
            'CURLOPT_CUSTOMREQUEST',
            'CURLOPT_POST',
            'CURLOPT_HTTPGET',
            'CURLOPT_NOBODY',
            'CURLOPT_PUT',
            'CURLOPT_UPLOAD',
            'CURLOPT_POSTFIELDS',
            'CURLOPT_READFUNCTION',
            'CURLOPT_INFILE',
            'CURLOPT_INFILESIZE',
            'CURLOPT_HTTPHEADER',
            'CURLOPT_HEADER',
            'CURLOPT_RETURNTRANSFER',
            'CURLOPT_HEADERFUNCTION',
            'CURLOPT_WRITEFUNCTION',
            'CURLOPT_FILE',
            'CURLOPT_FOLLOWLOCATION',
            'CURLOPT_MAXREDIRS',
            'CURLOPT_TIMEOUT',
            'CURLOPT_TIMEOUT_MS',
            'CURLOPT_CONNECTTIMEOUT',
            'CURLOPT_CONNECTTIMEOUT_MS',
            'CURLOPT_PROTOCOLS',
            'CURLOPT_PROTOCOLS_STR',
            'CURLOPT_REDIR_PROTOCOLS',
            'CURLOPT_REDIR_PROTOCOLS_STR',
            'CURLOPT_NOSIGNAL',
            'CURLOPT_USERAGENT',
            'CURLOPT_USERPWD',
            'CURLOPT_HTTPAUTH',
            'CURLOPT_SUPPRESS_CONNECT_HEADERS',
            // CURLHEADER_UNIFIED mandaría los headers (la clave) también al proxy, sin TLS.
            'CURLOPT_HEADEROPT',
        ];
        foreach ($managed as $name) {
            // Las *_STR existen desde PHP 8.3.
            if (\defined($name)) {
                yield $name => [
                    [(int) \constant($name) => 1],
                    "CurlClient no acepta {$name}: el SDK la maneja (URL, método, headers, body, "
                        . 'timeouts y redirects).',
                ];
            }
        }
        $tls = 'CurlClient no acepta desactivar la verificación TLS: si usás un certificado '
            . 'propio, pasalo con CURLOPT_CAINFO.';
        yield 'CURLOPT_SSL_VERIFYPEER false' => [[CURLOPT_SSL_VERIFYPEER => false], $tls];
        yield 'CURLOPT_SSL_VERIFYPEER 0' => [[CURLOPT_SSL_VERIFYPEER => 0], $tls];
        yield 'CURLOPT_SSL_VERIFYHOST 0' => [[CURLOPT_SSL_VERIFYHOST => 0], $tls];
        yield 'CURLOPT_SSL_VERIFYHOST 1' => [[CURLOPT_SSL_VERIFYHOST => 1], $tls];
        yield 'CURLOPT_SSL_VERIFYHOST false' => [[CURLOPT_SSL_VERIFYHOST => false], $tls];
        // Pasan solo 2, true y '2' (quedan como 2); el resto se rechaza, también en texto. curl
        // tomaría 2.0 como 2, sin aviso, pero el SDK lo rechaza igual: no es uno de esos tres.
        yield "CURLOPT_SSL_VERIFYHOST '0'" => [[CURLOPT_SSL_VERIFYHOST => '0'], $tls];
        yield "CURLOPT_SSL_VERIFYHOST '1'" => [[CURLOPT_SSL_VERIFYHOST => '1'], $tls];
        yield 'CURLOPT_SSL_VERIFYHOST 2.0' => [[CURLOPT_SSL_VERIFYHOST => 2.0], $tls];
        // El modo verbose escribe cada header de la request en STDERR (en el log de FPM o del
        // contenedor), con la clave entera, y CURLOPT_DEBUGFUNCTION (PHP 8.4+) se los pasa a tu
        // callback. Se rechazan con cualquier valor: en false no hace falta pasarla.
        $debug = ': muestra los headers de cada request, con la clave entera en Authorization. '
            . 'Para ver las requests, usá RAYA_LOG=debug.';
        yield 'CURLOPT_VERBOSE' => [
            [CURLOPT_VERBOSE => true],
            "CurlClient no acepta CURLOPT_VERBOSE{$debug}",
        ];
        yield 'CURLOPT_VERBOSE false' => [
            [CURLOPT_VERBOSE => false],
            "CurlClient no acepta CURLOPT_VERBOSE{$debug}",
        ];
        if (\defined('CURLOPT_DEBUGFUNCTION')) {
            yield 'CURLOPT_DEBUGFUNCTION' => [
                [(int) \constant('CURLOPT_DEBUGFUNCTION') => static fn(): int => 0],
                "CurlClient no acepta CURLOPT_DEBUGFUNCTION{$debug}",
            ];
        }
        yield 'un valor que curl no acepta' => [
            [CURLOPT_IPRESOLVE => 99],
            'CurlClient no pudo aplicar CURLOPT_IPRESOLVE: revisá su valor.',
        ];
    }

    /** @param array<int, mixed> $options */
    #[DataProvider('refusedCurlOptions')]
    public function testCurlClientRefusesWhatTheSdkManages(array $options, string $message): void
    {
        $error = self::assertThrows(\ValueError::class, static fn() => new CurlClient($options));
        self::assertSame($message, $error->getMessage());
    }

    /** PHP: las opciones son constantes CURLOPT_*, no sus nombres. */
    public function testCurlClientOptionsAreCurlConstants(): void
    {
        $error = self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (el nombre de la constante, a propósito)
            static fn() => new CurlClient(['CURLOPT_PROXY' => 'http://proxy.interno:3128']),
        );
        self::assertSame(
            'Las opciones de CurlClient son constantes CURLOPT_*, como CURLOPT_PROXY, no '
                . '"CURLOPT_PROXY".',
            $error->getMessage(),
        );
    }

    /**
     * PHP: si curl no puede usar el proxy (de CURLOPT_PROXY o de https_proxy), su error cita la
     * URL entera, con el usuario y la contraseña: quedan como «…», hasta la última @ (también si
     * la contraseña tiene una @, una comilla o un espacio). Si curl cortó el mensaje (en 255
     * bytes), no queda nada de la URL después del esquema (o de la comilla, si no tiene). Python
     * solo nombra el tipo de error.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function badProxies(): iterable
    {
        $password = 'proxyPASSWORDs3cr3t';
        // Un usuario y una contraseña largos, como un token: curl corta el mensaje antes de la @
        // del host, y la última @ que queda puede ser una de la contraseña.
        $token = str_repeat($password, 16);
        yield 'una contraseña larga, como un token' => [
            "http://ops:{$token}@127.0.0.1:99999",
            "'http://…).",
        ];
        yield 'una contraseña larga y un esquema que curl no conoce' => [
            "gopher://ops:{$token}@127.0.0.1:9",
            "'gopher://…).",
        ];
        yield 'una contraseña larga con una @' => [
            "http://ops:p@ss{$token}@127.0.0.1:99999",
            "'http://…).",
        ];
        yield 'un esquema que curl no conoce' => [
            "gopher://ops:{$password}@127.0.0.1:9",
            "'gopher://…@127.0.0.1:9'",
        ];
        yield 'un puerto imposible' => [
            "http://ops:{$password}@127.0.0.1:99999",
            "'http://…@127.0.0.1:99999'",
        ];
        yield 'una @ en la contraseña' => [
            "http://ops:p@ss{$password}@127.0.0.1:99999",
            "'http://…@127.0.0.1:99999'",
        ];
        yield 'una comilla en la contraseña' => [
            "http://ops:pa'ss{$password}@127.0.0.1:99999",
            "'http://…@127.0.0.1:99999'",
        ];
        yield 'un espacio en la contraseña' => [
            "http://ops:pa ss{$password}@127.0.0.1:99999",
            "'http://…@127.0.0.1:99999'",
        ];
        yield 'sin esquema' => ["ops:{$password}@[127.0.0.1:9", "'…@[127.0.0.1:9'"];
    }

    #[DataProvider('badProxies')]
    public function testProxyCredentialsStayOutOfCurlErrors(string $proxy, string $shown): void
    {
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => 'https://api.raya.cash.invalid',
            'http_client' => new CurlClient([CURLOPT_PROXY => $proxy]),
            'max_network_retries' => 0,
            'timeout' => 5,
        ]);
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertStringContainsString($shown, $error->getMessage());
        $previous = $error->getPrevious();
        self::assertInstanceOf(TransportException::class, $previous);
        foreach ([$error->getMessage(), $previous->getMessage(), $previous->getDetail()] as $text) {
            self::assertStringNotContainsString('PASSWORD', $text);
            self::assertStringNotContainsString('ops:', $text);
        }
    }

    /**
     * PHP: aunque una opción de las que CurlClient rechaza se cuele (por Reflection, o como se
     * colaba en un CurlClient deserializado), send() fuerza las que cuidan la clave: el modo
     * verbose apagado (escribiría el header Authorization) y los headers solo para la API, nunca
     * en el CONNECT de un proxy, que los vería sin TLS.
     */
    public function testSendForcesWhatKeepsTheKeyPrivate(): void
    {
        $log = tmpfile();
        self::assertIsResource($log);
        $curl = new CurlClient();
        self::sneakOptions($curl, [CURLOPT_VERBOSE => true, CURLOPT_STDERR => $log]);
        $this->client('json-200-BALANCE', curl: $curl)->balance->retrieve();
        $this->requests(1);
        rewind($log);
        self::assertSame('', stream_get_contents($log));
        fclose($log);

        // Por un proxy (el servidor de loopback, que anota el CONNECT y lo rechaza) hacia otro
        // host: el CONNECT no lleva la clave, ni con CURLHEADER_UNIFIED.
        $proxy = "http://127.0.0.1:{$this->server()->port}";
        $proxied = new CurlClient([CURLOPT_PROXY => $proxy]);
        if (\defined('CURLOPT_HEADEROPT')) {
            self::sneakOptions($proxied, [
                CURLOPT_PROXY => $proxy,
                (int) \constant('CURLOPT_HEADEROPT') => \constant('CURLHEADER_UNIFIED'),
            ]);
        }
        $client = new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => 'https://api.raya.cash.invalid',
            'http_client' => $proxied,
            'max_network_retries' => 0,
            'timeout' => 5,
        ]);
        self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'NETWORK_ERROR',
        );
        [$connect] = $this->requests(1);
        self::assertSame(['CONNECT', 'api.raya.cash.invalid:443'], [
            $connect['method'],
            $connect['target'],
        ]);
        self::assertArrayNotHasKey('authorization', $connect['headers']);
    }

    /**
     * Le pone a $curl estas opciones sin los chequeos de su constructor, por Reflection: lo que
     * hacía un CurlClient deserializado antes de que no se dejara serializar.
     *
     * @param array<int, mixed> $options
     */
    private static function sneakOptions(CurlClient $curl, array $options): void
    {
        $token = (new \ReflectionProperty(CurlClient::class, 'token'))->getValue($curl);
        $all = (new \ReflectionProperty(CurlClient::class, 'optionsOf'))->getValue();
        self::assertInstanceOf(\stdClass::class, $token);
        self::assertInstanceOf(\WeakMap::class, $all);
        $all[$token] = $options;
    }

    /**
     * Un RayaClient con un CurlClient de verdad que le habla al servidor de loopback, en el
     * escenario dado (ver tests/Support/loopback-server.php). Sin reintentos y con 5 s por
     * intento, salvo que pidas otra cosa: un test colgado termina igual.
     */
    private function client(
        string $scenario,
        int $retries = 0,
        float $timeout = 5.0,
        ?CurlClient $curl = null,
    ): RayaClient {
        return new RayaClient(Fixtures::TEST_KEY, [
            'api_base' => $this->server()->base($scenario),
            'http_client' => $curl ?? new CurlClient(),
            'max_network_retries' => $retries,
            'timeout' => $timeout,
        ]);
    }

    /** El servidor de este test: lo arranca la primera vez y lo frena tearDown(). */
    private function server(): LoopbackServer
    {
        return $this->server ??= LoopbackServer::start();
    }

    /**
     * Las requests que llegaron desde la última vez, que tienen que ser exactamente $count.
     *
     * @return list<Received>
     */
    private function requests(int $count): array
    {
        $requests = $this->server()->received($count);
        self::assertCount($count, $requests, 'Llegaron: ' . implode(', ', array_map(
            static fn(array $request): string => "{$request['method']} {$request['target']}",
            $requests,
        )));
        return $requests;
    }

    /** Un puerto de 127.0.0.1 donde no escucha nadie. */
    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }
}
