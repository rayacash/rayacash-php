<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\ConnectOrder;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\NotFoundException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\HttpClient\TransportException;
use RayaCash\ListObject;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\Tests\Support\FakeDecimal;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\User;
use RayaCash\Util\Uuid;

/** Los 12 endpoints: método, ruta, query, body y headers exactos (test_endpoints.py). */
final class EndpointsTest extends TestCase
{
    private const IDEMPOTENCY_KEY = '9b2f6c4e-1d3a-4e8b-a5c7-0f9e8d7c6b5a';
    private const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-'
        . '[0-9a-f]{12}\z/';

    /** Lo que llegó a la redirect_uri de la app de ejemplo, más sus credenciales. */
    private const TOKEN_PARAMS = [
        'client_id' => Fixtures::CLIENT_ID,
        'client_secret' => Fixtures::CLIENT_SECRET,
        'code' => Fixtures::CODE,
        'redirect_uri' => Fixtures::REDIRECT_URI,
    ];

    private const ITEM = ['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000];
    private const ITEM_EXAMPLE
        = "['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000]";

    public function testBalanceRetrieve(): void
    {
        $this->api->add(200, Fixtures::BALANCE);
        $balance = $this->api->client()->balance->retrieve();
        self::assertSame(Balance::class, $balance::class);
        self::assertSame(152340.5, $balance->available);
        self::assertSame('ARS', $balance->currency);
        $request = $this->api->last();
        self::assertSame(
            ['GET', 'https://api.raya.cash/v1/balance'],
            [$request->method, $request->url],
        );
        self::assertNull($request->body);
        self::assertNull($request->header('idempotency-key'));
        $response = $balance->getLastResponse();
        self::assertNotNull($response);
        self::assertSame('req_test00000000000000000001', $response->requestId);
        self::assertSame(200, $response->httpStatus);
        self::assertNull($response->idempotencyKey);
    }

    public function testTransferCreate(): void
    {
        $this->api->add(201, Fixtures::TRANSFER_OUTBOUND_PENDING);
        $transfer = $this->api->client()->transfers->create(
            ['amount' => new FakeDecimal('25000.00'), 'destination' => '0000123380736153041182'],
            ['idempotency_key' => self::IDEMPOTENCY_KEY],
        );
        self::assertSame(Transfer::class, $transfer::class);
        self::assertSame('pending', $transfer->status);
        self::assertSame('Proveedora Ejemplo S. A.', $transfer->counterparty->name);
        $request = $this->api->last();
        self::assertSame(
            ['POST', 'https://api.raya.cash/v1/transfers'],
            [$request->method, $request->url],
        );
        self::assertSame(
            '{"amount":25000,"destination":"0000123380736153041182"}',
            $request->body,
        );
        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame(self::IDEMPOTENCY_KEY, $request->header('idempotency-key'));
        self::assertSame(self::IDEMPOTENCY_KEY, $transfer->getLastResponse()?->idempotencyKey);
    }

    /** @return iterable<string, array{int|float|string|\Stringable, string}> */
    public static function amountForms(): iterable
    {
        yield 'float' => [25000.5, '25000.5'];
        yield 'string' => ['25000.50', '25000.5'];
        yield 'int' => [1, '1'];
        // Lo que en Node es un bigint: un decimal exacto (en PHP 8.4+, también BcMath\Number;
        // armado por nombre porque PHPStan analiza desde PHP 8.1, que no lo tiene).
        yield 'Stringable' => [new FakeDecimal('25000'), '25000'];
        $bcmath = 'BcMath\Number';
        if (class_exists($bcmath)) {
            $number = new $bcmath('25000.00');
            if ($number instanceof \Stringable) {
                yield 'BcMath\Number' => [$number, '25000'];
            }
        }
    }

    #[DataProvider('amountForms')]
    public function testTransferAmountForms(
        int|float|string|\Stringable $amount,
        string $expected,
    ): void {
        $this->api->add(201, Fixtures::TRANSFER_OUTBOUND_PENDING);
        $this->api->client()->transfers->create(
            ['amount' => $amount, 'destination' => 'proveedor.ejemplo'],
            ['idempotency_key' => 'k'],
        );
        self::assertSame(
            '{"amount":' . $expected . ',"destination":"proveedor.ejemplo"}',
            $this->api->last()->body,
        );
    }

    public function testTransferCreateRequiresAnIdempotencyKey(): void
    {
        $transfers = $this->api->client()->transfers;
        // Sin opciones: PHP no deja llamar sin el segundo argumento.
        self::assertThrows(
            \ArgumentCountError::class,
            // @phpstan-ignore arguments.count (lo que haría alguien sin PHPStan)
            static fn() => $transfers->create(['amount' => 1, 'destination' => 'x']),
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => $transfers->create(
                ['amount' => 1, 'destination' => 'x'],
                // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
                ['idempotency_key' => null],
            ),
            'transfers->create necesita idempotency_key',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $transfers->create(
                ['amount' => 1, 'destination' => 'x'],
                ['idempotency_key' => 'con espacios'],
            ),
            'idempotency_key tiene que tener de 1 a 255 caracteres ASCII visibles',
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => $transfers->create(
                // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
                ['amount' => 1, 'destination' => 123],
                ['idempotency_key' => 'k'],
            ),
            'destination tiene que ser un string',
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => $transfers->create(
                // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
                ['amount' => true, 'destination' => 'x'],
                ['idempotency_key' => 'k'],
            ),
            'amount no puede ser un bool',
        );
        // La clave entre los parámetros se perdería: va en las opciones (como en Node).
        foreach (['idempotency_key', 'idempotencyKey'] as $name) {
            self::assertThrows(
                \TypeError::class,
                static fn() => $transfers->create(
                    ['amount' => 1, 'destination' => 'x', $name => 'k'],
                    ['idempotency_key' => 'k'],
                ),
                "transfers->create no acepta {$name} entre los parámetros: va en las opciones",
            );
        }
        self::assertSame([], $this->api->requests);
    }

    public function testTransferRetrieve(): void
    {
        $this->api->add(200, Fixtures::TRANSFER_INBOUND);
        $transfer = $this->api->client()->transfers->retrieve(Fixtures::TRANSFER_INBOUND['id']);
        self::assertSame(Transfer::class, $transfer::class);
        self::assertSame('inbound', $transfer->direction);
        self::assertSame(48169.59, $transfer->net_amount);
        self::assertSame(
            ['GET', '/v1/transfers/' . Fixtures::TRANSFER_INBOUND['id']],
            [$this->api->last()->method, $this->api->last()->path],
        );
    }

    public function testIdsAreEncodedAndNeverEmpty(): void
    {
        $client = $this->api->client();
        $this->api->add(
            404,
            ['error' => ['code' => 'TRANSFER_NOT_FOUND', 'message' => 'x', 'data' => null]],
        );
        self::assertThrows(
            NotFoundException::class,
            static fn() => $client->transfers->retrieve('../balance'),
        );
        self::assertSame('/v1/transfers/..%2Fbalance', $this->api->last()->path);
        self::assertThrows(
            \ValueError::class,
            static fn() => $client->transfers->retrieve(''),
            'transferId no puede estar vacío.',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $client->payments->refund(' '),
            'paymentId no puede estar vacío.',
        );
        // PHP: un segmento de puntos sacaría la request de su ruta (curl lo resuelve):
        // retrieve('.') pediría /v1/transfers/ y refund('..'), POST /v1/refund.
        self::assertThrows(
            \ValueError::class,
            static fn() => $client->transfers->retrieve('.'),
            'transferId no puede ser "." ni "..".',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $client->payments->refund('..'),
            'paymentId no puede ser "." ni "..".',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => Payment::constructFrom(['object' => 'payment', 'id' => '.'])->refund(),
            'paymentId no puede ser "." ni "..".',
        );
        self::assertCount(1, $this->api->requests);
    }

    public function testTransferList(): void
    {
        $this->api->add(200, Fixtures::TRANSFER_LIST);
        $page = $this->api->client()->transfers->list([
            'direction' => 'inbound',
            'created_gte' => new \DateTimeImmutable('2026-10-23 03:00', new \DateTimeZone('UTC')),
            'created_lt' => '2026-10-24T00:00:00-03:00',
            'limit' => 2,
        ]);
        self::assertSame(ListObject::class, $page::class);
        $classes = [];
        foreach ($page as $transfer) {
            $classes[] = $transfer::class;
        }
        self::assertSame([Transfer::class, Transfer::class], $classes);
        self::assertTrue($page->has_more);
        $request = $this->api->last();
        self::assertSame(['GET', '/v1/transfers'], [$request->method, $request->path]);
        self::assertSame(
            [
                'direction' => 'inbound',
                'created_gte' => '2026-10-23T03:00:00+00:00',
                'created_lt' => '2026-10-24T00:00:00-03:00',
                'limit' => '2',
            ],
            $request->query,
        );

        // El "+" de un offset viaja codificado: si no, el servidor lo leería como un espacio.
        $this->api->add(
            200,
            [...Fixtures::TRANSFER_LIST, 'has_more' => false, 'next_cursor' => null],
        );
        $this->api->client()->transfers->list(['created_gte' => '2026-10-23T00:00:00+00:00']);
        self::assertStringContainsString(
            '?created_gte=2026-10-23T00%3A00%3A00%2B00%3A00',
            $this->api->last()->url,
        );
    }

    public function testTransferListWithoutFilters(): void
    {
        $this->api->add(
            200,
            [...Fixtures::TRANSFER_LIST, 'has_more' => false, 'next_cursor' => null],
        );
        $this->api->client()->transfers->list();
        self::assertSame('https://api.raya.cash/v1/transfers', $this->api->last()->url);
    }

    public function testPaymentCreate(): void
    {
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $payment = $this->api->client()->payments->create([
            'amount' => 15000,
            'description' => 'Pedido #1042',
            'external_reference' => 'pedido-1042',
            'return_url' => 'https://tienda.example.com/pedidos/1042',
            'metadata' => ['cliente_id' => '8812'],
        ]);
        self::assertSame(Payment::class, $payment::class);
        self::assertSame('0000123322813466534007', $payment->bank_transfer->cvu);
        self::assertStringEndsWith(Fixtures::PAYMENT_ID, $payment->checkout_url);
        $request = $this->api->last();
        self::assertSame(['POST', '/v1/payments'], [$request->method, $request->path]);
        self::assertSame(
            [
                'amount' => 15000,
                'description' => 'Pedido #1042',
                'external_reference' => 'pedido-1042',
                'return_url' => 'https://tienda.example.com/pedidos/1042',
                'metadata' => ['cliente_id' => '8812'],
            ],
            $this->api->lastJson(),
        );
        // Sin clave propia, generamos un UUID v4 para esta llamada.
        self::assertMatchesRegularExpression(
            self::UUID_V4,
            $request->header('idempotency-key') ?? '',
        );
    }

    /**
     * Sin idempotency_key, cada llamada genera la suya (invariante 3: una vez por llamada, la
     * misma en sus reintentos). Una clave repetida haría que el segundo pago diera 409
     * IDEMPOTENCY_KEY_REUSED, o el primero de nuevo si los parámetros coinciden.
     */
    public function testGeneratedIdempotencyKeysAreNewOnEachCall(): void
    {
        $client = $this->api->client();
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $client->payments->create(['amount' => 15000]);
        $client->payments->create(['amount' => 15000]);
        $client->payments->refund(Fixtures::PAYMENT_ID);
        $keys = [];
        foreach ($this->api->requests as $request) {
            $key = $request->header('idempotency-key') ?? '';
            self::assertMatchesRegularExpression(self::UUID_V4, $key);
            $keys[] = $key;
        }
        self::assertCount(3, $keys);
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    /** La que el README recomienda guardar antes de llamar: un UUID v4 nuevo cada vez. */
    public function testUuidV4(): void
    {
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = Uuid::v4();
        }
        foreach ($uuids as $uuid) {
            self::assertMatchesRegularExpression(self::UUID_V4, $uuid);
        }
        self::assertCount(100, array_unique($uuids));
    }

    public function testPaymentCreateSendsOnlyWhatYouPass(): void
    {
        $client = $this->api->client();
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(
            ['amount' => new FakeDecimal('15000.50'), 'description' => null, 'return_url' => null],
            ['idempotency_key' => 'pedido-1042-pago'],
        );
        self::assertSame('{"amount":15000.5}', $this->api->last()->body);
        self::assertSame('pedido-1042-pago', $this->api->last()->header('idempotency-key'));
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => $client->payments->create(['amount' => 1, 'metadata' => [['a', 'b']]]),
            'metadata tiene que ser un array de string a string',
        );
        self::assertCount(1, $this->api->requests);

        // PHP: la metadata viaja siempre como objeto JSON. Un [] de PHP saldría como lista, que
        // la API rechaza: va como {}. Un stdClass también vale.
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $client->payments->create(['amount' => 1, 'metadata' => []]);
        self::assertSame('{"amount":1,"metadata":{}}', $this->api->last()->body);
        $client->payments->create(['amount' => 1, 'metadata' => (object) ['a' => 'b']]);
        self::assertSame('{"amount":1,"metadata":{"a":"b"}}', $this->api->last()->body);
    }

    /**
     * Una lista no es metadata, como en Python (tiene que ser un dict). Pero PHP convierte las
     * claves "0", "1"… en una lista, también en la metadata de un pago que ya trajiste: el error
     * dice cómo mandarla. Otras claves que parecen enteros vuelven como int y viajan igual.
     */
    public function testPaymentMetadataWithIndexKeys(): void
    {
        $client = $this->api->client();
        $list = 'metadata tiene que ser un array de string a string, como '
            . "['cliente_id' => '8812'], no una lista. Si sus claves son \"0\", \"1\"…, PHP la "
            . 'convirtió en una: mandala como (object) $metadata.';
        $create = static fn(mixed $metadata): Payment => $client->payments->create(
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            ['amount' => 1500, 'metadata' => $metadata],
        );
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => $create(['0' => 'sku-1', '1' => 'sku-2']),
        );
        self::assertSame($list, $error->getMessage());
        $error = self::assertThrows(\TypeError::class, static fn() => $create('cliente_id=8812'));
        self::assertSame(
            "metadata tiene que ser un array de string a string, como ['cliente_id' => '8812'].",
            $error->getMessage(),
        );

        $this->api->add(
            200,
            [...Fixtures::PAYMENT_PENDING, 'metadata' => (object) ['sku-1', 'sku-2']],
        );
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        self::assertSame(['sku-1', 'sku-2'], $payment->metadata);
        $error = self::assertThrows(\TypeError::class, static fn() => $create($payment->metadata));
        self::assertSame($list, $error->getMessage());
        self::assertCount(1, $this->api->requests);
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $create((object) $payment->metadata);
        self::assertSame(
            '{"amount":1500,"metadata":{"0":"sku-1","1":"sku-2"}}',
            $this->api->last()->body,
        );

        $this->api->add(
            200,
            [...Fixtures::PAYMENT_PENDING, 'metadata' => ['8812' => 'sku-1', 'canal' => 'web']],
        );
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        self::assertSame([8812 => 'sku-1', 'canal' => 'web'], $payment->metadata);
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $create($payment->metadata);
        self::assertSame(
            '{"amount":1500,"metadata":{"8812":"sku-1","canal":"web"}}',
            $this->api->last()->body,
        );
    }

    public function testPaymentRetrieve(): void
    {
        $this->api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $this->api->client()->payments->retrieve(Fixtures::PAYMENT_ID);
        self::assertSame('succeeded', $payment->status);
        self::assertSame('0000123300602983525937', $payment->payer?->account);
        self::assertSame(
            ['GET', '/v1/payments/' . Fixtures::PAYMENT_ID],
            [$this->api->last()->method, $this->api->last()->path],
        );
    }

    public function testPaymentRefund(): void
    {
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $payment = $this->api->client()->payments->refund(
            Fixtures::PAYMENT_ID,
            ['idempotency_key' => 'reembolso-1'],
        );
        self::assertSame('refunded', $payment->status);
        $request = $this->api->last();
        self::assertSame(
            ['POST', '/v1/payments/' . Fixtures::PAYMENT_ID . '/refund'],
            [$request->method, $request->path],
        );
        // El reembolso va sin body. El Content-Length: 0 lo pone CurlClient: lo mira
        // TransportTest, contra un servidor de verdad.
        self::assertNull($request->body);
        self::assertNull($request->header('content-type'));
        self::assertSame('reembolso-1', $request->header('idempotency-key'));
    }

    // --- Raya Connect: apps y cuentas conectadas --------------------------------------------

    /** POST /v1/oauth/token: sin Authorization ni Idempotency-Key; grant_type lo pone el SDK. */
    public function testOAuthToken(): void
    {
        $this->api->add(200, Fixtures::OAUTH_TOKEN);
        // Un cliente sin clave alcanza: la app se identifica con client_id y client_secret.
        $token = $this->api->client(null)->oauth->token(self::TOKEN_PARAMS);
        self::assertSame(OAuthToken::class, $token::class);
        self::assertSame(Fixtures::ACCESS_TOKEN, $token->access_token);
        self::assertSame(Fixtures::USER_ID, $token->user_id);
        self::assertSame(['Bearer', 'orders profile'], [$token->token_type, $token->scope]);
        self::assertNull($token->expires_in);
        self::assertNull($token->refresh_token);
        $request = $this->api->last();
        self::assertSame(
            ['POST', 'https://api.raya.cash/v1/oauth/token'],
            [$request->method, $request->url],
        );
        self::assertSame(
            [
                'client_id' => Fixtures::CLIENT_ID,
                'client_secret' => Fixtures::CLIENT_SECRET,
                'grant_type' => 'authorization_code',
                'code' => Fixtures::CODE,
                'redirect_uri' => Fixtures::REDIRECT_URI,
            ],
            $this->api->lastJson(),
        );
        self::assertSame('application/json', $request->header('content-type'));
        self::assertNull($request->header('authorization'));
        self::assertArrayNotHasKey('Authorization', $request->headers());
        self::assertNull($request->header('idempotency-key'));
        $response = $token->getLastResponse();
        self::assertNotNull($response);
        self::assertSame(
            ['req_test00000000000000000001', null],
            [$response->requestId, $response->idempotencyKey],
        );

        // Con un cliente con clave es igual: la clave no viaja. Y las otras opciones sí valen.
        $this->api->add(200, Fixtures::OAUTH_TOKEN);
        $this->api->client()->oauth->token(self::TOKEN_PARAMS, ['timeout' => 5]);
        self::assertNull($this->api->last()->header('authorization'));
        $request = $this->api->last();
        self::assertSame([5.0, 5.0], [$request->timeout, $request->connectTimeout]);

        // Los errores de la API, con su clase: OAUTH_CLIENT_INVALID es 401 y no se reintenta.
        $this->api->addError(
            401,
            'OAUTH_CLIENT_INVALID',
            'El client_id o el client_secret no son válidos.',
        );
        $oauth = $this->api->client(null)->oauth;
        self::assertThrows(
            AuthenticationException::class,
            static fn() => $oauth->token(self::TOKEN_PARAMS),
            errorCode: 'OAUTH_CLIENT_INVALID',
        );
        $this->api->addError(400, 'OAUTH_GRANT_INVALID');
        self::assertThrows(
            InvalidRequestException::class,
            static fn() => $oauth->token(self::TOKEN_PARAMS),
            errorCode: 'OAUTH_GRANT_INVALID',
        );
        self::assertCount(4, $this->api->requests);
    }

    public function testOAuthTokenRejectsWhatCannotWork(): void
    {
        $oauth = $this->api->client(null)->oauth;
        // Los cuatro parámetros van como string, y ninguno puede faltar.
        foreach (array_keys(self::TOKEN_PARAMS) as $name) {
            $without = self::TOKEN_PARAMS;
            unset($without[$name]);
            self::assertThrows(
                \TypeError::class,
                // @phpstan-ignore argument.type (a propósito: falta un parámetro)
                static fn() => $oauth->token($without),
                "oauth->token necesita {$name} como string (",
            );
        }
        $error = self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => $oauth->token([...self::TOKEN_PARAMS, 'client_id' => 7650337728110670]),
        );
        self::assertSame(
            'oauth->token necesita client_id como string (el número de tu aplicación, como '
                . "'7650337728110670', entre comillas), no int.",
            $error->getMessage(),
        );
        self::assertThrows(
            \TypeError::class,
            static fn() => $oauth->token([...self::TOKEN_PARAMS, 'code' => '']),
            'oauth->token necesita code como string (el code que llegó a la query de tu '
                . 'redirect_uri), no uno vacío.',
        );
        // grant_type lo pone el SDK: no es un parámetro.
        self::assertThrows(
            \TypeError::class,
            static fn() => $oauth->token(
                [...self::TOKEN_PARAMS, 'grant_type' => 'authorization_code'],
            ),
            'oauth->token no acepta "grant_type": los parámetros son client_id, client_secret, '
                . 'code y redirect_uri.',
        );
        // Sin Authorization no hay api_key, y sin Idempotency-Key no hay idempotency_key.
        foreach ([Fixtures::TEST_KEY, 123] as $apiKey) {
            self::assertThrows(
                \TypeError::class,
                // Un int, a propósito: también se rechaza.
                static fn() => $oauth->token(self::TOKEN_PARAMS, ['api_key' => $apiKey]),
                'oauth->token no acepta api_key en las opciones: no lleva Authorization, la app '
                    . 'se identifica con client_id y client_secret.',
            );
        }
        self::assertThrows(
            \TypeError::class,
            static fn() => $oauth->token(self::TOKEN_PARAMS, ['idempotency_key' => 'k']),
            'oauth->token no acepta idempotency_key: no lleva Idempotency-Key, el code se canjea '
                . 'una sola vez.',
        );
        self::assertSame([], $this->api->requests);
    }

    /** Sin Idempotency-Key, el error de un corte no dice que se reintente con la misma. */
    public function testOAuthTokenConnectionErrorDoesNotMentionTheIdempotencyKey(): void
    {
        $this->api->failWith(TransportException::network('sin red'));
        $oauth = $this->api->client(null, ['max_network_retries' => 0])->oauth;
        $error = self::assertThrows(
            ApiConnectionException::class,
            static fn() => $oauth->token(self::TOKEN_PARAMS),
            errorCode: 'NETWORK_ERROR',
        );
        self::assertSame(
            'No pudimos conectarnos con la API de Raya (sin red).',
            $error->getMessage(),
        );
        self::assertNull($error->getIdempotencyKey());
    }

    /** GET /v1/users/:id, con el access_token de la cuenta en Authorization, como una clave. */
    public function testUserRetrieve(): void
    {
        $this->api->add(200, Fixtures::USER);
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $user = $connected->users->retrieve(Fixtures::USER_ID);
        self::assertSame(User::class, $user::class);
        self::assertSame(['personal', 'Lucía'], [$user->type, $user->first_name]);
        self::assertSame('34567890', $user->identification?->number);
        self::assertSame('Almacén Ejemplo', $user->merchant?->name);
        $request = $this->api->last();
        self::assertSame(
            ['GET', '/v1/users/' . Fixtures::USER_ID],
            [$request->method, $request->path],
        );
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $request->header('authorization'));
        self::assertNull($request->body);
        self::assertNull($request->header('idempotency-key'));

        // 'me' también vale; cualquier otro id es USER_NOT_FOUND (nunca revela si existe).
        $this->api->add(200, Fixtures::USER);
        $connected->users->retrieve('me');
        self::assertSame('/v1/users/me', $this->api->last()->path);
        $this->api->addError(404, 'USER_NOT_FOUND');
        self::assertThrows(
            NotFoundException::class,
            static fn() => $connected->users->retrieve('9d4f2b7e-0000-4e0a-9b8f-2a5c7d1e6f40'),
            errorCode: 'USER_NOT_FOUND',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $connected->users->retrieve(''),
            'userId no puede estar vacío.',
        );
        self::assertCount(3, $this->api->requests);
    }

    /** POST /v1/connect/orders: el body en el orden de la API, con la metadata como objeto. */
    public function testConnectOrderCreate(): void
    {
        $this->api->add(201, Fixtures::CONNECT_ORDER_PENDING);
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $connectOrder = $connected->connect->orders->create([
            'items' => [self::ITEM],
            'fee' => 500,
            'external_reference' => 'pedido-1042',
            'back_urls' => [
                'success' => 'https://tienda.example.com/pedidos/1042',
                'failure' => 'https://tienda.example.com/pedidos/1042/error',
            ],
            'auto_return' => 'approved',
            'notification_url' => Fixtures::NOTIFICATION_URL,
            'metadata' => ['pedido_id' => '1042'],
        ]);
        self::assertSame(ConnectOrder::class, $connectOrder::class);
        self::assertSame('pending', $connectOrder->status);
        self::assertSame(
            [45000, 500, 44500],
            [$connectOrder->amount, $connectOrder->fee, $connectOrder->net_amount],
        );
        self::assertSame('0000123390456538582824', $connectOrder->bank_transfer->cvu);
        // La página de pago sigue en raya.cash/checkout/<id>.
        self::assertStringEndsWith(
            '/checkout/' . Fixtures::CONNECT_ORDER_ID,
            $connectOrder->checkout_url,
        );
        self::assertSame(Fixtures::USER_ID, $connectOrder->account);
        $request = $this->api->last();
        self::assertSame(['POST', '/v1/connect/orders'], [$request->method, $request->path]);
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $request->header('authorization'));
        self::assertSame(
            '{"items":[{"title":"Pedido 1042","quantity":1,"unit_price":45000}],"fee":500,'
                . '"external_reference":"pedido-1042","metadata":{"pedido_id":"1042"},'
                . '"back_urls":{"success":"https://tienda.example.com/pedidos/1042",'
                . '"failure":"https://tienda.example.com/pedidos/1042/error"},'
                . '"auto_return":"approved",'
                . '"notification_url":"' . Fixtures::NOTIFICATION_URL . '"}',
            $request->body,
        );
        // Sin clave propia, generamos un UUID v4 para esta llamada, como en payments->create.
        self::assertMatchesRegularExpression(
            self::UUID_V4,
            $request->header('idempotency-key') ?? '',
        );
    }

    /** fee y unit_price viajan como números JSON exactos, como amount; lo demás, solo si vino. */
    public function testConnectOrderCreateSendsCanonicalAmountsAndOnlyWhatYouPass(): void
    {
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $this->api->add(201, Fixtures::CONNECT_ORDER_PENDING);
        $connected->connect->orders->create(
            [
                'items' => [
                    ['title' => 'Remera', 'quantity' => 2, 'unit_price' => '1500.50'],
                    [
                        'title' => 'Envio',
                        'quantity' => 1,
                        'unit_price' => new FakeDecimal('800.00'),
                    ],
                ],
                'fee' => 120.5,
                'description' => null,
                'back_urls' => null,
                'expires_in' => 3600,
            ],
            ['idempotency_key' => 'pedido-1043-connect'],
        );
        self::assertSame(
            '{"items":[{"title":"Remera","quantity":2,"unit_price":1500.5},'
                . '{"title":"Envio","quantity":1,"unit_price":800}],"fee":120.5,"expires_in":3600}',
            $this->api->last()->body,
        );
        self::assertSame('pedido-1043-connect', $this->api->last()->header('idempotency-key'));

        // Una metadata vacía viaja como {}, y unas back_urls sin URLs no viajan.
        $this->api->add(201, Fixtures::CONNECT_ORDER_PENDING);
        $connected->connect->orders->create([
            'items' => [self::ITEM],
            'fee' => null,
            'metadata' => [],
            'back_urls' => ['success' => null],
        ]);
        self::assertSame(
            '{"items":[{"title":"Pedido 1042","quantity":1,"unit_price":45000}],"metadata":{}}',
            $this->api->last()->body,
        );

        // Un ítem sin un campo viaja sin él: lo reclama la API, que nombra el ítem.
        $this->api->addError(
            400,
            'PARAMETER_MISSING',
            'Falta el parámetro items[0].title.',
            ['field' => 'items[0].title'],
        );
        $error = self::assertThrows(
            InvalidRequestException::class,
            static fn() => $connected->connect->orders->create(
                // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
                ['items' => [['quantity' => 1, 'unit_price' => 45000], ['title' => 'Envio']]],
            ),
            errorCode: 'PARAMETER_MISSING',
        );
        self::assertSame('items[0].title', $error->getParam());
        self::assertSame(
            '{"items":[{"quantity":1,"unit_price":45000},{"title":"Envio"}]}',
            $this->api->last()->body,
        );
        self::assertCount(3, $this->api->requests);
    }

    /** Lo que nunca puede andar falla antes de mandar nada; los rangos los valida la API. */
    public function testConnectOrderCreateRejectsWhatCannotWork(): void
    {
        $orders = $this->api->client(Fixtures::ACCESS_TOKEN)->connect->orders;
        // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan: los casos de abajo)
        $create = static fn(array $params): ConnectOrder => $orders->create($params);
        $cases = [
            [
                ['items' => self::ITEM],
                'items tiene que ser una lista de ítems, como [' . self::ITEM_EXAMPLE
                    . '], no array.',
            ],
            [
                ['items' => 'Pedido 1042'],
                'items tiene que ser una lista de ítems, como [' . self::ITEM_EXAMPLE
                    . '], no string.',
            ],
            [
                ['items' => [['Pedido 1042', 1, 45000]]],
                'items[0] tiene que ser un array con title, quantity y unit_price, como '
                    . self::ITEM_EXAMPLE . ', no array.',
            ],
            [
                ['items' => [self::ITEM, 'Envio']],
                'items[1] tiene que ser un array con title, quantity y unit_price, como '
                    . self::ITEM_EXAMPLE . ', no string.',
            ],
            [
                ['items' => [[...self::ITEM, 'sku' => 'A1']]],
                'items[0] no acepta "sku": los parámetros son title, quantity y unit_price.',
            ],
            [
                ['items' => [[...self::ITEM, 'unit_price' => true]]],
                'items[0].unit_price no puede ser un bool: mandá un número en pesos, como 1500 o '
                    . '1500.5.',
            ],
            [
                ['items' => [self::ITEM], 'fee' => true],
                'fee no puede ser un bool: mandá un número en pesos, como 1500 o 1500.5.',
            ],
            [
                ['items' => [self::ITEM], 'back_urls' => 'https://tienda.example.com/pedidos/1042'],
                "back_urls tiene que ser un array con success y/o failure, como ['success' => "
                    . "'https://tienda.example.com/pedidos/1042'], no string.",
            ],
            [
                ['items' => [self::ITEM], 'back_urls' => ['pending' => 'https://x.example']],
                'back_urls no acepta "pending": los parámetros son success y failure.',
            ],
            [
                ['items' => [self::ITEM], 'metadata' => ['sku-1', 'sku-2']],
                "metadata tiene que ser un array de string a string, como ['cliente_id' => "
                    . "'8812'], no una lista. Si sus claves son \"0\", \"1\"…, PHP la convirtió en "
                    . 'una: mandala como (object) $metadata.',
            ],
            [
                ['items' => [self::ITEM], 'marketplace_fee' => 500],
                'connect->orders->create no acepta "marketplace_fee": los parámetros son items, '
                    . 'fee, description, external_reference, metadata, back_urls, auto_return, '
                    . 'notification_url y expires_in.',
            ],
            [
                ['items' => [self::ITEM], 'notificationUrl' => Fixtures::NOTIFICATION_URL],
                'connect->orders->create no acepta "notificationUrl" (¿quisiste decir '
                    . 'notification_url?): los parámetros son items, fee, description, '
                    . 'external_reference, metadata, back_urls, auto_return, notification_url y '
                    . 'expires_in.',
            ],
            [
                ['items' => [self::ITEM], 'idempotency_key' => 'k'],
                'connect->orders->create no acepta idempotency_key entre los parámetros: va en '
                    . "las opciones, el último argumento (['idempotency_key' => …]).",
            ],
        ];
        foreach ($cases as [$params, $message]) {
            $error = self::assertThrows(\TypeError::class, static fn() => $create($params));
            self::assertSame($message, $error->getMessage());
        }
        self::assertThrows(
            \ValueError::class,
            static fn() => $create(['items' => [self::ITEM], 'fee' => 'cien']),
            'fee "cien" no es un número: usá punto decimal y nada más, como "1500.50".',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $create(['items' => [[...self::ITEM, 'unit_price' => '']]]),
            'items[0].unit_price "" no es un número',
        );
        self::assertSame([], $this->api->requests);
    }

    public function testConnectOrderRetrieve(): void
    {
        $this->api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $connectOrder = $connected->connect->orders->retrieve(Fixtures::CONNECT_ORDER_ID);
        self::assertSame('succeeded', $connectOrder->status);
        self::assertSame(45000, $connectOrder->amount_received);
        self::assertSame('0000123300602983525937', $connectOrder->payer?->account);
        self::assertSame(
            ['GET', '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID],
            [$this->api->last()->method, $this->api->last()->path],
        );
        self::assertSame(
            'Bearer ' . Fixtures::ACCESS_TOKEN,
            $this->api->last()->header('authorization'),
        );
        $this->api->addError(404, 'ORDER_NOT_FOUND', 'No encontramos ese pago.');
        self::assertThrows(
            NotFoundException::class,
            static fn() => $connected->connect->orders->retrieve(Fixtures::PAYMENT_ID),
            errorCode: 'ORDER_NOT_FOUND',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => $connected->connect->orders->retrieve('..'),
            'orderId no puede ser "." ni "..".',
        );
        self::assertCount(2, $this->api->requests);
    }

    public function testConnectOrderRefund(): void
    {
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $connectOrder = $connected->connect->orders->refund(
            Fixtures::CONNECT_ORDER_ID,
            ['idempotency_key' => 'reembolso-1'],
        );
        self::assertSame('refunded', $connectOrder->status);
        $request = $this->api->last();
        self::assertSame(
            ['POST', '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund'],
            [$request->method, $request->path],
        );
        // Como el de un pago (Payment): sin body ni Content-Type.
        self::assertNull($request->body);
        self::assertNull($request->header('content-type'));
        self::assertSame('reembolso-1', $request->header('idempotency-key'));

        // Desde el pago, con el cliente del que vino: devuelve uno nuevo, este no cambia.
        $this->api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        $succeeded = $connected->connect->orders->retrieve(Fixtures::CONNECT_ORDER_ID);
        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $refunded = $succeeded->refund();
        self::assertSame(['refunded', 'succeeded'], [$refunded->status, $succeeded->status]);
        self::assertSame(
            '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund',
            $this->api->last()->path,
        );
        self::assertSame(
            'Bearer ' . Fixtures::ACCESS_TOKEN,
            $this->api->last()->header('authorization'),
        );
        self::assertMatchesRegularExpression(
            self::UUID_V4,
            $this->api->last()->header('idempotency-key') ?? '',
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => ConnectOrder::constructFrom(['object' => 'connect.order'])->refund(),
            'Este ConnectOrder no tiene id: no se puede reembolsar.',
        );
        self::assertCount(3, $this->api->requests);
    }

    public function testErrorsComeWithTheRequestId(): void
    {
        $this->api->addError(422, 'INSUFFICIENT_BALANCE', 'El monto supera tu saldo disponible.');
        $client = $this->api->client();
        $error = self::assertThrows(
            UnprocessableEntityException::class,
            static fn() => $client->transfers->create(
                ['amount' => 10 ** 9, 'destination' => 'alias.prueba'],
                ['idempotency_key' => 'k'],
            ),
        );
        self::assertSame('INSUFFICIENT_BALANCE', $error->getErrorCode());
        self::assertSame('req_test00000000000000000001', $error->getRequestId());
        self::assertSame('k', $error->getIdempotencyKey());
        self::assertCount(1, $this->api->requests); // los 4xx no se reintentan
    }

    /**
     * PHP descarta en silencio los argumentos de más: retrieve($id, [], $opciones) mandaría la
     * request sin esas opciones (con la clave de otra cuenta, por ejemplo). Acá fallan antes.
     * PHPStan no los marca (func_num_args() vuelve variádico al método): solo queda este chequeo.
     *
     * @return iterable<string, array{\Closure(RayaClient): mixed, string}>
     */
    public static function extraArguments(): iterable
    {
        $options = ['api_key' => Fixtures::OTHER_KEY];
        $params = ['amount' => 1, 'destination' => 'x'];
        $key = ['idempotency_key' => 'k'];
        yield 'balance->retrieve' => [
            static fn(RayaClient $client): mixed => $client->balance->retrieve([], $options),
            'balance->retrieve recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield 'transfers->create' => [
            static fn(RayaClient $client): mixed => $client->transfers->create(
                $params,
                $key,
                $options,
            ),
            'transfers->create recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'transfers->retrieve' => [
            static fn(RayaClient $client): mixed => $client->transfers->retrieve('t', [], $options),
            'transfers->retrieve recibe 2 argumentos (transferId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'transfers->list' => [
            static fn(RayaClient $client): mixed => $client->transfers->list([], [], $options),
            'transfers->list recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'payments->create' => [
            static fn(RayaClient $client): mixed => $client->payments->create(
                $params,
                [],
                $options,
            ),
            'payments->create recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'payments->retrieve' => [
            static fn(RayaClient $client): mixed => $client->payments->retrieve('p', [], $options),
            'payments->retrieve recibe 2 argumentos (paymentId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'payments->refund' => [
            static fn(RayaClient $client): mixed => $client->payments->refund('p', [], $options),
            'payments->refund recibe 2 argumentos (paymentId y options), no 3: las opciones van '
                . "en el segundo, como ['api_key' => …].",
        ];
        yield 'oauth->token' => [
            static fn(RayaClient $client): mixed => $client->oauth->token(
                self::TOKEN_PARAMS,
                [],
                $options,
            ),
            'oauth->token recibe 2 argumentos (params y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'users->retrieve' => [
            static fn(RayaClient $client): mixed => $client->users->retrieve('u', [], $options),
            'users->retrieve recibe 2 argumentos (userId y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'connect->orders->create' => [
            static fn(RayaClient $client): mixed => $client->connect->orders->create(
                ['items' => [self::ITEM]],
                [],
                $options,
            ),
            'connect->orders->create recibe 2 argumentos (params y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'connect->orders->retrieve' => [
            static fn(RayaClient $client): mixed => $client->connect->orders->retrieve(
                'c',
                [],
                $options,
            ),
            'connect->orders->retrieve recibe 2 argumentos (orderId y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
        yield 'connect->orders->refund' => [
            static fn(RayaClient $client): mixed => $client->connect->orders->refund(
                'c',
                [],
                $options,
            ),
            'connect->orders->refund recibe 2 argumentos (orderId y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
    }

    /** @param \Closure(RayaClient): mixed $call */
    #[DataProvider('extraArguments')]
    public function testExtraArgumentsFailBeforeSending(\Closure $call, string $message): void
    {
        $client = $this->api->client();
        $error = self::assertThrows(\ArgumentCountError::class, static fn() => $call($client));
        self::assertSame($message, $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /**
     * Las opciones en una lista, sin nombres (retrieve($id, [$otraClave])), fallan antes de
     * mandar nada, como el «options tiene que ser un dict» de Python, y sin mostrar la clave.
     *
     * @return iterable<string, array{\Closure(RayaClient): mixed}>
     */
    public static function optionsInAList(): iterable
    {
        $options = [Fixtures::OTHER_KEY];
        yield 'balance->retrieve' => [
            static fn(RayaClient $client): mixed => $client->balance->retrieve($options),
        ];
        yield 'transfers->create' => [
            static fn(RayaClient $client): mixed => $client->transfers->create(
                ['amount' => 1, 'destination' => 'x'],
                // @phpstan-ignore argument.type (una lista, a propósito)
                $options,
            ),
        ];
        yield 'transfers->retrieve' => [
            static fn(RayaClient $client): mixed => $client->transfers->retrieve('t', $options),
        ];
        yield 'transfers->list' => [
            static fn(RayaClient $client): mixed => $client->transfers->list([], $options),
        ];
        yield 'payments->create' => [
            static fn(RayaClient $client): mixed => $client->payments->create(
                ['amount' => 1],
                $options,
            ),
        ];
        yield 'payments->retrieve' => [
            static fn(RayaClient $client): mixed => $client->payments->retrieve('p', $options),
        ];
        yield 'payments->refund' => [
            static fn(RayaClient $client): mixed => $client->payments->refund('p', $options),
        ];
        yield 'oauth->token' => [
            static fn(RayaClient $client): mixed => $client->oauth->token(
                self::TOKEN_PARAMS,
                $options,
            ),
        ];
        yield 'users->retrieve' => [
            static fn(RayaClient $client): mixed => $client->users->retrieve('u', $options),
        ];
        yield 'connect->orders->create' => [
            static fn(RayaClient $client): mixed => $client->connect->orders->create(
                ['items' => [self::ITEM]],
                $options,
            ),
        ];
        yield 'connect->orders->retrieve' => [
            static fn(RayaClient $client): mixed => $client->connect->orders->retrieve(
                'c',
                $options,
            ),
        ];
        yield 'connect->orders->refund' => [
            static fn(RayaClient $client): mixed => $client->connect->orders->refund(
                'c',
                $options,
            ),
        ];
    }

    /** @param \Closure(RayaClient): mixed $call */
    #[DataProvider('optionsInAList')]
    public function testOptionsInAListFailBeforeSending(\Closure $call): void
    {
        $client = $this->api->client();
        $error = self::assertThrows(\TypeError::class, static fn() => $call($client));
        self::assertSame(
            "options tiene que ser un array con nombres, como ['timeout' => 90], no una lista.",
            $error->getMessage(),
        );
        self::assertStringNotContainsString(Fixtures::OTHER_KEY, $error->getMessage());
        self::assertSame([], $this->api->requests);
    }
}
