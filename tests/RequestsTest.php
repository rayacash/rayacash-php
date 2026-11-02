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
use RayaCash\Exception\PermissionDeniedException;
use RayaCash\Exception\RateLimitException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\HttpClient\TransportException;
use RayaCash\Internal\Log;
use RayaCash\Internal\Timing;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\RayaClient;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\RecordedRequest;
use RayaCash\Tests\Support\Stderr;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Util\LoggerInterface;

/**
 * Reintentos, idempotencia y logging del núcleo de requests (test_requests.py).
 *
 * Donde Python encola una excepción de httpx (ConnectError, ReadTimeout, LocalProtocolError),
 * acá va una TransportException, lo que tira un HttpClientInterface cuando no llegó respuesta:
 * network() reintentable o no, y timeout(). Cómo CurlClient elige cada una según el errno de
 * curl lo prueba TransportTest, contra un servidor de verdad.
 */
final class RequestsTest extends TestCase
{
    private const RETRY_ON_POST = ' Reintentá con la misma Idempotency-Key: nunca se ejecuta dos '
        . 'veces.';

    public function testRetriesReuseTheSameIdempotencyKey(): void
    {
        $this->api->addError(503, 'TEMPORARILY_UNAVAILABLE', headers: ['Retry-After' => '5']);
        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $payment = self::createPayment($this->api->client());
        $keys = array_values(array_unique($this->idempotencyKeys()));
        self::assertCount(3, $this->api->requests);
        self::assertCount(1, $keys);
        $response = $payment->getLastResponse();
        self::assertNotNull($response);
        self::assertSame($keys[0], $response->idempotencyKey);
        self::assertSame([5.0, 1.0], $this->sleeps); // Retry-After y después el backoff
        // PHP: cada reintento manda los mismos bytes.
        self::assertSame(
            array_fill(0, 3, '{"amount":15000}'),
            array_map(static fn(RecordedRequest $r): ?string => $r->body, $this->api->requests),
        );
    }

    public function testGivesUpAfterMaxNetworkRetries(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->api->addError(500, 'INTERNAL_ERROR');
        }
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiException::class,
            static fn() => self::createPayment($client, ['idempotency_key' => 'pedido-1']),
        );
        self::assertCount(3, $this->api->requests);
        self::assertSame([0.5, 1.0], $this->sleeps);
        self::assertSame('INTERNAL_ERROR', $error->getErrorCode());
        self::assertSame('pedido-1', $error->getIdempotencyKey());
    }

    /**
     * @return iterable<string, array{
     *     int,
     *     string,
     *     array<string, string>,
     *     class-string<RayaException>,
     * }>
     */
    public static function notRetried(): iterable
    {
        $rows = [
            [409, 'IDEMPOTENCY_KEY_REUSED', [], IdempotencyException::class],
            [409, 'PAYMENT_NOT_REFUNDABLE', [], ConflictException::class],
            [503, 'API_UNAVAILABLE', [], ApiException::class],
            [429, 'RATE_LIMITED', ['Retry-After' => '120'], RateLimitException::class],
            [503, 'BANK_UNAVAILABLE', ['Retry-After' => '3600'], ApiException::class],
            [400, 'PARAMETER_INVALID', [], InvalidRequestException::class],
            [401, 'API_KEY_EXPIRED', [], AuthenticationException::class],
            [403, 'API_NOT_ENABLED', [], PermissionDeniedException::class],
            [422, 'REFUND_FAILED', [], UnprocessableEntityException::class],
        ];
        foreach ($rows as [$status, $code, $headers, $class]) {
            yield self::rowName($status, $code, $headers) => [$status, $code, $headers, $class];
        }
    }

    /**
     * @param array<string, string> $headers
     * @param class-string<RayaException> $class
     */
    #[DataProvider('notRetried')]
    public function testNotRetried(int $status, string $code, array $headers, string $class): void
    {
        $this->api->addError($status, $code, headers: $headers);
        $client = $this->api->client();
        $error = self::assertThrows(
            $class,
            static fn() => $client->payments->refund(Fixtures::PAYMENT_ID),
        );
        // PHP: la clase exacta (pytest.raises acepta subclases: IdempotencyException pasaría
        // por ConflictException) y el código.
        self::assertSame($class, $error::class);
        self::assertSame($code, $error->getErrorCode());
        self::assertCount(1, $this->api->requests);
        self::assertSame([], $this->sleeps);
        if (isset($headers['Retry-After'])) {
            self::assertSame((float) $headers['Retry-After'], $error->getRetryAfter());
        }
    }

    /** @return iterable<string, array{int, string, array<string, string>, float}> */
    public static function retried(): iterable
    {
        $rows = [
            [409, 'IDEMPOTENCY_KEY_IN_USE', ['Retry-After' => '2'], 2.0],
            [429, 'RATE_LIMITED', ['Retry-After' => '7'], 7.0],
            [429, 'RATE_LIMITED', [], 0.5],
            [503, 'CVU_UNAVAILABLE', ['Retry-After' => '5'], 5.0],
            [503, 'BANK_UNAVAILABLE', [], 0.5],
            [503, 'BANK_UNAVAILABLE', ['Retry-After' => '30'], 30.0],
            // PHP: el header se lee sin distinguir mayúsculas (HTTP/2 lo manda en minúscula).
            [429, 'RATE_LIMITED', ['retry-after' => '7'], 7.0],
        ];
        foreach ($rows as [$status, $code, $headers, $sleep]) {
            yield self::rowName($status, $code, $headers) => [$status, $code, $headers, $sleep];
        }
    }

    /** @param array<string, string> $headers */
    #[DataProvider('retried')]
    public function testRetried(
        int $status,
        string $code,
        array $headers,
        float $expectedSleep,
    ): void {
        $this->api->addError($status, $code, headers: $headers);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $payment = $this->api->client()->payments->refund(Fixtures::PAYMENT_ID);
        self::assertSame('refunded', $payment->status);
        self::assertCount(2, $this->api->requests);
        self::assertSame([$expectedSleep], $this->sleeps);
        // PHP: el reintento del reembolso lleva la misma clave que generó el SDK.
        self::assertCount(1, array_unique($this->idempotencyKeys()));
    }

    public function testEdgeHtmlErrorsAreRetried(): void
    {
        RayaCash::setLog('info'); // PHP: para ver el código sintetizado en la línea del reintento
        $this->api->add(
            502,
            headers: ['Content-Type' => 'text/html'],
            text: '<html><body>Bad gateway</body></html>',
        );
        $this->api->add(200, Fixtures::BALANCE);
        self::assertSame(152340.5, $this->api->client()->balance->retrieve()->available);
        self::assertCount(2, $this->api->requests);
        self::assertSame([0.5], $this->sleeps);
        self::assertContains(
            'message="Reintentando la request" method=GET path=/v1/balance retry=1 '
                . 'reason=INTERNAL_ERROR delay=0.50s',
            $this->logLines,
        );
    }

    /**
     * httpx.ConnectError("sin red") y httpx.ReadTimeout("lento") de Python, más el timeout de
     * conexión de Node (UND_ERR_CONNECT_TIMEOUT), que en curl es también el errno 28.
     *
     * @return iterable<string, array{TransportException, string}>
     */
    public static function connectionErrors(): iterable
    {
        yield 'un corte de red (NETWORK_ERROR)' => [
            TransportException::network('sin red'),
            'NETWORK_ERROR',
        ];
        yield 'el timeout del intento (TIMEOUT)' => [
            TransportException::timeout('lento'),
            'TIMEOUT',
        ];
        yield 'el timeout de conexión (TIMEOUT)' => [
            TransportException::timeout('curl 28: Connection timed out after 10001 milliseconds'),
            'TIMEOUT',
        ];
    }

    #[DataProvider('connectionErrors')]
    public function testConnectionErrorsAreRetried(TransportException $error, string $code): void
    {
        // PHP: con el log en info se ve el código (en Python solo está en el id del test).
        RayaCash::setLog('info');
        $this->api->failWith($error);
        $this->api->add(201, Fixtures::TRANSFER_OUTBOUND_PENDING);
        $transfer = $this->api->client()->transfers->create(
            ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
            ['idempotency_key' => 'k'],
        );
        self::assertSame('pending', $transfer->status);
        self::assertSame(['k', 'k'], $this->idempotencyKeys());
        self::assertSame([0.5], $this->sleeps);
        self::assertContains(
            'message="Reintentando la request" method=POST path=/v1/transfers retry=1 '
                . "reason={$code} delay=0.50s",
            $this->logLines,
        );
    }

    public function testConnectionErrorAfterTheLastRetry(): void
    {
        $cause = TransportException::timeout('lento');
        $this->api->failWith(TransportException::timeout('lento'));
        $this->api->failWith(TransportException::timeout('lento'));
        $this->api->failWith($cause);
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->transfers->create(
                ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
                ['idempotency_key' => 'k'],
            ),
        );
        self::assertSame('TIMEOUT', $error->getErrorCode());
        self::assertTrue($error->shouldRetry());
        self::assertSame('k', $error->getIdempotencyKey());
        self::assertStringContainsString('misma Idempotency-Key', $error->getMessage());
        self::assertSame(
            'La API de Raya no respondió a tiempo.' . self::RETRY_ON_POST,
            $error->getMessage(),
        );
        // El __cause__ de Python es getPrevious(): el error del último intento (como en Node).
        self::assertSame($cause, $error->getPrevious());
        // PHP: sin respuesta, no hay status (getCode() es 0).
        self::assertNull($error->getHttpStatus());
        self::assertSame(0, $error->getCode());
        self::assertCount(3, $this->api->requests);
        self::assertSame([0.5, 1.0], $this->sleeps);
    }

    public function testGetConnectionErrorsHaveNoIdempotencyKey(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->api->failWith(TransportException::network('sin red'));
        }
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
        );
        self::assertSame('NETWORK_ERROR', $error->getErrorCode());
        self::assertNull($error->getIdempotencyKey());
        // Como en Node, el mensaje nombra lo que pasó (acá, getDetail(); en Python, la clase de
        // httpx) y, en un GET, no habla de la Idempotency-Key.
        self::assertSame(
            'No pudimos conectarnos con la API de Raya (sin red).',
            $error->getMessage(),
        );
        self::assertStringNotContainsString('Idempotency-Key', $error->getMessage());
    }

    public function testLocalProtocolErrorsAreNotRetried(): void
    {
        // httpx.LocalProtocolError: un error de la request misma, que daría lo mismo reintentar.
        $this->api->failWith(TransportException::network('header inválido', false));
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $client->balance->retrieve(),
        );
        self::assertFalse($error->shouldRetry());
        self::assertSame('NETWORK_ERROR', $error->getErrorCode());
        self::assertCount(1, $this->api->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testAnyOtherHttpClientErrorPassesThrough(): void
    {
        // De Node: lo que no es una TransportException (un bug del cliente HTTP propio) sale tal
        // cual, sin reintentos.
        $bug = new \RuntimeException('un bug del cliente HTTP propio');
        $this->api->failWith($bug);
        $client = $this->api->client();
        self::assertSame($bug, self::thrown(static fn() => $client->balance->retrieve()));
        self::assertCount(1, $this->api->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testMaxNetworkRetriesPerClientAndPerRequest(): void
    {
        $client = $this->api->client(Fixtures::TEST_KEY, ['max_network_retries' => 0]);
        $this->api->addError(500, 'INTERNAL_ERROR');
        self::assertThrows(ApiException::class, static fn() => $client->balance->retrieve());
        self::assertCount(1, $this->api->requests);

        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve(['max_network_retries' => 4]);
        self::assertCount(4, $this->api->requests);

        $default = $this->api->client();
        $this->api->addError(500, 'INTERNAL_ERROR');
        self::assertThrows(
            ApiException::class,
            static fn() => $default->balance->retrieve(['max_network_retries' => 0]),
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $default->balance->retrieve(['max_network_retries' => -1]),
            'max_network_retries no puede ser negativo.',
        );
        self::assertCount(5, $this->api->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotAJsonObject(): iterable
    {
        yield '"no es json"' => ['no es json'];
        yield '"[1, 2]"' => ['[1, 2]'];
        yield '""' => [''];
        // PHP: con json_decode(…, true), [] y {} serían el mismo array vacío.
        yield '"[]"' => ['[]'];
        yield '"null"' => ['null'];
    }

    #[DataProvider('bodiesThatAreNotAJsonObject')]
    public function testSuccessWithoutAJsonObject(string $body): void
    {
        $this->api->add(200, text: $body);
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiException::class,
            static fn() => $client->balance->retrieve(),
            errorCode: 'INVALID_RESPONSE',
        );
        self::assertSame(200, $error->getHttpStatus());
        self::assertSame(
            'La API respondió 200 con un body que no es un objeto JSON.',
            $error->getMessage(),
        );
        self::assertSame('req_test00000000000000000001', $error->getRequestId());
        self::assertSame($body, $error->getHttpBody());
        self::assertCount(1, $this->api->requests);
    }

    public function testSuccessWithoutAJsonObjectOnAPost(): void
    {
        // PHP: el pago pudo crearse aunque no entendamos la respuesta: el error trae la clave
        // para reintentar con la misma (como el idempotency_key del APIError de Python).
        $this->api->add(201, text: '<html>ok</html>');
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiException::class,
            static fn() => self::createPayment($client, ['idempotency_key' => 'pedido-1']),
            errorCode: 'INVALID_RESPONSE',
        );
        self::assertSame(
            'La API respondió 201 con un body que no es un objeto JSON.',
            $error->getMessage(),
        );
        self::assertSame('pedido-1', $error->getIdempotencyKey());
        self::assertCount(1, $this->api->requests);
    }

    /** @return iterable<string, array{int, array<string, mixed>|null, array<string, string>}> */
    public static function redirects(): iterable
    {
        yield '301' => [301, null, ['Location' => 'https://otro.example/v1/balance']];
        // PHP: solo un 2xx sale bien (200 <= status < 300, como en Python): un 300 con un
        // objeto JSON tampoco se lee.
        yield '300 con un saldo' => [300, Fixtures::BALANCE, []];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    #[DataProvider('redirects')]
    public function testRedirectsAreNeverFollowed(int $status, ?array $json, array $headers): void
    {
        if ($json === null) {
            $this->api->add($status, headers: $headers, text: '');
        } else {
            $this->api->add($status, $json, $headers);
        }
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiException::class,
            static fn() => $client->balance->retrieve(),
        );
        self::assertSame($status, $error->getHttpStatus());
        self::assertCount(1, $this->api->requests);
        // PHP: la clase exacta, el código sintetizado y su mensaje.
        self::assertSame(ApiException::class, $error::class);
        self::assertSame('INVALID_RESPONSE', $error->getErrorCode());
        self::assertSame(
            "La API respondió algo inesperado (HTTP {$status}).",
            $error->getMessage(),
        );
        self::assertSame([], $this->sleeps);
    }

    public function testListEndpointThatDoesNotReturnAList(): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiException::class,
            static fn() => $client->transfers->list(),
            'no es una lista',
            'INVALID_RESPONSE',
        );
        self::assertSame('La API respondió algo que no es una lista.', $error->getMessage());
        self::assertSame(200, $error->getHttpStatus());
        // PHP: con la respuesta, como el resto de los errores (invariante 6). Python y Node acá
        // traen solo el status (una divergencia declarada en el CHANGELOG).
        self::assertSame('req_test00000000000000000001', $error->getRequestId());
        self::assertSame('application/json', $error->getHttpHeaders()->get('content-type'));
        self::assertSame(Fixtures::BALANCE, json_decode((string) $error->getHttpBody(), true));
        self::assertNull($error->getIdempotencyKey()); // un GET no lleva clave
        self::assertCount(1, $this->api->requests);
    }

    /**
     * PHP: un endpoint de un recurso que responde OTRO tipo de objeto conocido falla igual que la
     * lista de Python (el método no podría devolverlo: PHP chequea el tipo de retorno).
     *
     * @return iterable<string, array{\Closure(RayaClient): mixed, array<string, mixed>, string}>
     */
    public static function otherObjectTypes(): iterable
    {
        yield 'balance->retrieve' => [
            static fn(RayaClient $client): mixed => $client->balance->retrieve(),
            Fixtures::PAYMENT_PENDING,
            'un saldo',
        ];
        yield 'transfers->create' => [
            static fn(RayaClient $client): mixed => $client->transfers->create(
                ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
                ['idempotency_key' => 'k'],
            ),
            Fixtures::PAYMENT_PENDING,
            'una transferencia',
        ];
        yield 'transfers->retrieve' => [
            static fn(RayaClient $client): mixed => $client->transfers->retrieve('t'),
            Fixtures::BALANCE,
            'una transferencia',
        ];
        yield 'payments->create' => [
            static fn(RayaClient $client): mixed => $client->payments->create(['amount' => 15000]),
            Fixtures::TRANSFER_INBOUND,
            'un pago',
        ];
        yield 'payments->retrieve' => [
            static fn(RayaClient $client): mixed => $client->payments->retrieve(
                Fixtures::PAYMENT_ID,
            ),
            Fixtures::TRANSFER_LIST,
            'un pago',
        ];
        yield 'payments->refund' => [
            static fn(RayaClient $client): mixed => $client->payments->refund(Fixtures::PAYMENT_ID),
            Fixtures::EVENT_PAYMENT_SUCCEEDED,
            'un pago',
        ];
    }

    /**
     * @param \Closure(RayaClient): mixed $call
     * @param array<string, mixed> $json
     */
    #[DataProvider('otherObjectTypes')]
    public function testResourceEndpointThatReturnsAnotherObjectType(
        \Closure $call,
        array $json,
        string $noun,
    ): void {
        $this->api->add(200, $json);
        $client = $this->api->client();
        $error = self::assertThrows(
            ApiException::class,
            static fn() => $call($client),
            errorCode: 'INVALID_RESPONSE',
        );
        self::assertSame("La API respondió algo que no es {$noun}.", $error->getMessage());
        self::assertSame(200, $error->getHttpStatus());
        // Como todo error de una respuesta (invariante 6): el request id, los headers, el body y,
        // en un POST, la Idempotency-Key que se usó, para reintentar con la misma.
        self::assertSame('req_test00000000000000000001', $error->getRequestId());
        self::assertSame('application/json', $error->getHttpHeaders()->get('content-type'));
        self::assertSame($json, json_decode((string) $error->getHttpBody(), true));
        $request = $this->api->last();
        self::assertSame($request->header('idempotency-key'), $error->getIdempotencyKey());
        if ($request->method === 'POST') {
            self::assertNotNull($error->getIdempotencyKey());
        } else {
            self::assertNull($error->getIdempotencyKey());
        }
        self::assertCount(1, $this->api->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testUnknownObjectTypesHydrateAsTheExpectedClass(): void
    {
        // PHP: un tipo nuevo (o ninguno) no es un error: queda como lo que esperaba el método.
        $this->api->add(200, ['object' => 'saldo_nuevo', 'available' => 1500, 'currency' => 'ARS']);
        $this->api->add(200, ['available' => 1500, 'currency' => 'ARS']);
        $client = $this->api->client();
        $nuevo = $client->balance->retrieve();
        self::assertSame(Balance::class, $nuevo::class);
        self::assertSame('saldo_nuevo', $nuevo['object']);
        self::assertSame(1500, $nuevo->available);
        $sinTipo = $client->balance->retrieve();
        self::assertSame(Balance::class, $sinTipo::class);
        self::assertSame(1500, $sinTipo->available);
    }

    public function testRetryAfterCanBeAnHttpDate(): void
    {
        // PHP: la forma de fecha se mide con el reloj del SDK (Timing::$now).
        $this->now = 1_700_000_000.0; // Tue, 14 Nov 2023 22:13:20 GMT
        $client = $this->api->client();
        $this->api->addError(
            503,
            'TEMPORARILY_UNAVAILABLE',
            headers: ['Retry-After' => 'Tue, 14 Nov 2023 22:13:27 GMT'],
        );
        $this->api->add(200, Fixtures::BALANCE);
        $client->balance->retrieve();
        self::assertSame([7.0], $this->sleeps);

        // A más de 60 s no se reintenta: el error trae los segundos para reprogramar.
        $this->api->addError(
            429,
            'RATE_LIMITED',
            headers: ['Retry-After' => 'Tue, 14 Nov 2023 22:15:20 GMT'],
        );
        $error = self::assertThrows(
            RateLimitException::class,
            static fn() => $client->balance->retrieve(),
        );
        self::assertSame(120.0, $error->getRetryAfter());
        self::assertCount(3, $this->api->requests);
        self::assertSame([7.0], $this->sleeps);
    }

    public function testInfoLogsNeverIncludeTheKeyOrTheBody(): void
    {
        $client = $this->api->client();
        RayaCash::setLog('info');
        // El caplog de Python: un logger (PSR-3 o Util\LoggerInterface) que recibe cada línea.
        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $info = [];

            /** @var list<string> */
            public array $debug = [];

            /** @var list<array<string, mixed>> */
            public array $infoContext = [];

            /** @var list<array<string, mixed>> */
            public array $debugContext = [];

            /** @param array<string, mixed> $context */
            public function info(string $message, array $context = []): void
            {
                $this->info[] = $message;
                $this->infoContext[] = $context;
            }

            /** @param array<string, mixed> $context */
            public function debug(string $message, array $context = []): void
            {
                $this->debug[] = $message;
                $this->debugContext[] = $context;
            }
        };
        RayaCash::setLogger($logger);
        // PHP: elapsed fijo, para comparar las líneas enteras con las de Python.
        Timing::$monotonic = static fn(): float => 0.0;
        $this->api->addError(503, 'TEMPORARILY_UNAVAILABLE', headers: ['Retry-After' => '5']);
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(
            ['amount' => 15000, 'description' => 'Pedido secreto'],
            ['idempotency_key' => 'clave-1'],
        );
        $err = $this->logOutput();
        self::assertStringContainsString(
            'message="Request a la API de Raya" method=POST path=/v1/payments attempt=1',
            $err,
        );
        self::assertStringContainsString('status=503', $err);
        self::assertStringContainsString('Reintentando', $err);
        self::assertStringContainsString('reason=TEMPORARILY_UNAVAILABLE', $err);
        self::assertStringNotContainsString(Fixtures::TEST_KEY, $err);
        self::assertStringNotContainsString('Pedido secreto', $err);
        self::assertStringNotContainsString('clave-1', $err); // la idempotency key solo en debug
        self::assertStringContainsString('status=201', $err); // Node lo mira en stderr
        self::assertSame(
            [
                'message="Request a la API de Raya" method=POST path=/v1/payments attempt=1',
                'message="Respuesta de la API de Raya" method=POST path=/v1/payments status=503 '
                    . 'request_id=req_test00000000000000000001 elapsed=0.000s',
                'message="Reintentando la request" method=POST path=/v1/payments retry=1 '
                    . 'reason=TEMPORARILY_UNAVAILABLE delay=5.00s',
                'message="Request a la API de Raya" method=POST path=/v1/payments attempt=2',
                'message="Respuesta de la API de Raya" method=POST path=/v1/payments status=201 '
                    . 'request_id=req_test00000000000000000002 elapsed=0.000s',
            ],
            $this->logLines,
        );

        // Lo que vio el logger en info, como caplog.at_level(logging.INFO) en Python.
        $info = implode("\n", $logger->info);
        self::assertStringContainsString('status=201', $info);
        self::assertSame($this->logLines, $logger->info);
        self::assertSame(
            [
                'method' => 'POST',
                'path' => '/v1/payments',
                'status' => 201,
                'request_id' => 'req_test00000000000000000002',
                'elapsed' => '0.000s',
            ],
            $logger->infoContext[4] ?? null,
        );
        // PHP: el logger recibe también las líneas debug (como el logger «rayacash» de Python),
        // pero nunca la clave ni el body.
        self::assertSame(
            array_fill(0, 2, 'message="Detalle de la request" query="" idempotency_key=clave-1'),
            $logger->debug,
        );
        // Con sus campos como contexto, igual que las de info.
        self::assertSame(
            array_fill(0, 2, ['query' => '', 'idempotency_key' => 'clave-1']),
            $logger->debugContext,
        );
        $everything = $info . "\n" . implode("\n", $logger->debug);
        self::assertStringNotContainsString(Fixtures::TEST_KEY, $everything);
        self::assertStringNotContainsString('Pedido secreto', $everything);
    }

    public function testDebugLogsIncludeTheIdempotencyKey(): void
    {
        $client = $this->api->client();
        Log::resetEnv('debug'); // RAYA_LOG=debug, como lo leyó el SDK del entorno
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $this->api->add(
            200,
            ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null],
        );
        $client->payments->create(['amount' => 15000], ['idempotency_key' => 'clave-1']);
        // De Node: también la query de una lista.
        $client->transfers->list(['direction' => 'inbound', 'limit' => 5]);
        $err = $this->logOutput();
        self::assertStringContainsString('idempotency_key=clave-1', $err);
        self::assertStringContainsString('query="direction=inbound&limit=5"', $err);
        self::assertStringNotContainsString(Fixtures::TEST_KEY, $err);
        // Las líneas debug enteras, como las de Python.
        self::assertContains(
            'message="Detalle de la request" query="" idempotency_key=clave-1',
            $this->logLines,
        );
        self::assertContains(
            'message="Detalle de la request" query="direction=inbound&limit=5" idempotency_key=""',
            $this->logLines,
        );

        // Un valor con comillas o barras invertidas va entre comillas, con las dos escapadas
        // (el _logfmt de Python): una Idempotency-Key puede traer las dos.
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(['amount' => 15000], ['idempotency_key' => 'k"1\\x']);
        self::assertContains(
            'message="Detalle de la request" query="" idempotency_key="k\"1\\\\x"',
            $this->logLines,
        );
    }

    /**
     * RayaCash::setLog() pisa a RAYA_LOG, para los dos lados (rayacash.log or RAYA_LOG, en
     * Python): con RAYA_LOG=debug y setLog('info'), las líneas debug no salen.
     */
    public function testSetLogWinsOverRayaLog(): void
    {
        $client = $this->api->client();
        $detail = static fn(string $line): bool => str_contains($line, 'Detalle de la request');
        Log::resetEnv('debug');
        RayaCash::setLog('info');
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(['amount' => 15000], ['idempotency_key' => 'clave-1']);
        self::assertCount(2, $this->logLines); // la request y la respuesta
        self::assertSame([], array_filter($this->logLines, $detail));

        $this->logLines = [];
        Log::resetEnv('info');
        RayaCash::setLog('debug');
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(['amount' => 15000], ['idempotency_key' => 'clave-1']);
        self::assertCount(1, array_filter($this->logLines, $detail));
    }

    public function testLoggingIsQuietByDefault(): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client()->balance->retrieve();
        self::assertSame('', $this->logOutput());
    }

    public function testALoggerGetsEveryLineWithoutRayaLog(): void
    {
        // PHP: como el logger «rayacash» de Python, el de RayaCash::setLogger() recibe cada
        // línea (también las debug) aunque no haya RAYA_LOG; la consola sigue en silencio.
        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $lines = [];

            /** @param array<string, mixed> $context */
            public function info(string $message, array $context = []): void
            {
                $this->lines[] = "info {$message}";
            }

            /** @param array<string, mixed> $context */
            public function debug(string $message, array $context = []): void
            {
                $this->lines[] = "debug {$message}";
            }
        };
        RayaCash::setLogger($logger);
        Timing::$monotonic = static fn(): float => 0.0;
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $this->api->client()->payments->refund(
            Fixtures::PAYMENT_ID,
            ['idempotency_key' => 'reembolso-1'],
        );
        self::assertSame([], $this->logLines);
        $path = '/v1/payments/' . Fixtures::PAYMENT_ID . '/refund';
        self::assertSame(
            [
                'info message="Request a la API de Raya" method=POST path=' . $path
                    . ' attempt=1',
                'debug message="Detalle de la request" query="" idempotency_key=reembolso-1',
                'info message="Respuesta de la API de Raya" method=POST path=' . $path
                    . ' status=200 request_id=req_test00000000000000000001 elapsed=0.000s',
            ],
            $logger->lines,
        );
    }

    /**
     * PHP: RAYA_LOG se lee una vez, de $_ENV, $_SERVER o getenv() (en ese orden), como la clave.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function logEnvironments(): iterable
    {
        yield '$_ENV' => ['$_ENV', 'info', true];
        yield '$_SERVER' => ['$_SERVER', 'info', true];
        yield 'getenv()' => ['getenv', 'debug', true];
        // Solo info o debug, tal cual (como en Python).
        yield 'INFO' => ['getenv', 'INFO', false];
        yield 'true' => ['$_ENV', 'true', false];
    }

    #[DataProvider('logEnvironments')]
    public function testRayaLogFromTheEnvironment(string $source, string $value, bool $logs): void
    {
        if ($source === '$_ENV') {
            $_ENV['RAYA_LOG'] = $value;
        } elseif ($source === '$_SERVER') {
            $_SERVER['RAYA_LOG'] = $value;
        } else {
            putenv("RAYA_LOG={$value}");
        }
        Log::forgetEnv(); // como un proceso que arranca con RAYA_LOG (tearDown la borra)
        Timing::$monotonic = static fn(): float => 0.0;
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client()->balance->retrieve();
        $lines = [
            'message="Request a la API de Raya" method=GET path=/v1/balance attempt=1',
            'message="Respuesta de la API de Raya" method=GET path=/v1/balance status=200 '
                . 'request_id=req_test00000000000000000001 elapsed=0.000s',
        ];
        self::assertSame($logs ? $lines : [], $this->logLines);
    }

    /**
     * La consola de verdad, la que leen capsys en Python y process.stderr en Node (los demás
     * tests la cambian por Log::$sink): en la terminal, cada línea va a STDERR, y nada a la
     * salida estándar (PHPUnit falla si un test escribe ahí).
     */
    public function testTheConsoleIsStderr(): void
    {
        Log::$sink = null;
        Log::resetEnv('info');
        Timing::$monotonic = static fn(): float => 0.0;
        $this->api->add(200, Fixtures::BALANCE);
        $client = $this->api->client();
        $err = Stderr::capture(static fn() => $client->balance->retrieve());
        self::assertSame(
            'message="Request a la API de Raya" method=GET path=/v1/balance attempt=1' . "\n"
                . 'message="Respuesta de la API de Raya" method=GET path=/v1/balance status=200 '
                . 'request_id=req_test00000000000000000001 elapsed=0.000s' . "\n",
            $err,
        );
    }

    /**
     * create_payment() de Python: un pago de 15000.
     *
     * @param array{idempotency_key?: string} $options
     */
    private static function createPayment(RayaClient $client, array $options = []): Payment
    {
        return $client->payments->create(['amount' => 15000], $options);
    }

    /**
     * El nombre de una fila: «429 RATE_LIMITED (Retry-After: 120)».
     *
     * @param array<string, string> $headers
     */
    private static function rowName(int $status, string $code, array $headers): string
    {
        $name = "{$status} {$code}";
        foreach ($headers as $header => $value) {
            $name .= " ({$header}: {$value})";
        }
        return $name;
    }

    /** @return list<string|null> La Idempotency-Key de cada request, en orden. */
    private function idempotencyKeys(): array
    {
        return array_map(
            static fn(RecordedRequest $request): ?string => $request->header('idempotency-key'),
            $this->api->requests,
        );
    }
}
