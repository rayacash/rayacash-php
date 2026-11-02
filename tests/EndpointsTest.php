<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\Exception\NotFoundException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\ListObject;
use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\Tests\Support\FakeDecimal;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\Util\Uuid;

/** Los 7 endpoints: método, ruta, query, body y headers exactos (test_endpoints.py). */
final class EndpointsTest extends TestCase
{
    private const IDEMPOTENCY_KEY = '9b2f6c4e-1d3a-4e8b-a5c7-0f9e8d7c6b5a';
    private const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-'
        . '[0-9a-f]{12}\z/';

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
