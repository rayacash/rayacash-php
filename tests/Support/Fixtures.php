<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

/**
 * Los objetos de ejemplo de la referencia de la API (raya.cash/llm/api.md), copiados tal cual de
 * tests/fixtures.py de rayacash-python (y de fixtures.ts de rayacash-node). Las claves de prueba
 * vienen de su helpers.py.
 *
 * Son arrays de PHP con los mismos valores y el mismo orden: MockApi::add() los manda como JSON.
 * Un objeto JSON vacío ({}) no se puede escribir como array: donde importe, va new \stdClass().
 */
final class Fixtures
{
    // Partidas en dos para que ningún escáner de secretos las tome por claves reales.
    public const TEST_KEY = 'raya_sk_live_' . '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKL';
    public const OTHER_KEY = 'raya_sk_live_' . 'ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvutsrqponmlkjihgfe';

    public const BALANCE = ['object' => 'balance', 'available' => 152340.5, 'currency' => 'ARS'];

    public const TRANSFER_OUTBOUND_PENDING = [
        'id' => '8f4d2c1e-6b7a-4e39-9c05-2a1b3d4e5f60',
        'object' => 'transfer',
        'direction' => 'outbound',
        'status' => 'pending',
        'amount' => 25000,
        'fee' => 0,
        'net_amount' => 25000,
        'currency' => 'ARS',
        'counterparty' => [
            'name' => 'Proveedora Ejemplo S. A.',
            'tax_id' => '30123456782',
            'account' => '0000123380736153041182',
            'alias' => 'proveedor.ejemplo',
        ],
        'description' => 'Transferencia',
        'reference' => null,
        'created_at' => '2026-10-24T13:58:12Z',
    ];

    public const TRANSFER_INBOUND = [
        'id' => '3b9e7a14-2c5d-4f86-a1b0-9d8c7e6f5a43',
        'object' => 'transfer',
        'direction' => 'inbound',
        'status' => 'completed',
        'amount' => 48300,
        'fee' => 130.41,
        'net_amount' => 48169.59,
        'currency' => 'ARS',
        'counterparty' => [
            'name' => 'Juan Ejemplo',
            'tax_id' => '20123456785',
            'account' => '0000123319112992888845',
            'alias' => null,
        ],
        'description' => 'Alquiler octubre',
        'reference' => 'K4P9W2X7M1Q8V3B6N5D0RT',
        'created_at' => '2026-10-24T14:05:41Z',
    ];

    // Como {**TRANSFER_OUTBOUND_PENDING, …} en Python: las claves repetidas quedan en su lugar.
    public const TRANSFER_OUTBOUND_COMPLETED = [
        ...self::TRANSFER_OUTBOUND_PENDING,
        'status' => 'completed',
        'reference' => 'R8T2N6V1X9K4M7Q3B5W0PZ',
    ];

    public const NEXT_CURSOR
        = 'MjAyNi0xMC0yNFQxMzo1ODoxMlp8OGY0ZDJjMWUtNmI3YS00ZTM5LTljMDUtMmExYjNkNGU1ZjYw';

    public const TRANSFER_LIST = [
        'object' => 'list',
        'data' => [self::TRANSFER_INBOUND, self::TRANSFER_OUTBOUND_COMPLETED],
        'has_more' => true,
        'next_cursor' => self::NEXT_CURSOR,
    ];

    public const PAYMENT_ID = 'c2a7e5d1-9b34-4f0a-8e62-71d5b3c9a0f8';

    public const PAYMENT_PENDING = [
        'id' => self::PAYMENT_ID,
        'object' => 'payment',
        'status' => 'pending',
        'amount' => 15000,
        'amount_received' => null,
        'currency' => 'ARS',
        'description' => 'Pedido #1042',
        'external_reference' => 'pedido-1042',
        'bank_transfer' => ['cvu' => '0000123322813466534007', 'alias' => 'raya.cash.ejemplo'],
        'checkout_url' => 'https://raya.cash/payments/' . self::PAYMENT_ID,
        'return_url' => 'https://tienda.example.com/pedidos/1042',
        'payer' => null,
        'failure_code' => null,
        'metadata' => ['cliente_id' => '8812'],
        'expires_at' => '2026-10-25T14:00:00Z',
        'paid_at' => null,
        'refunded_at' => null,
        'created_at' => '2026-10-24T14:00:00Z',
    ];

    public const PAYMENT_SUCCEEDED = [
        ...self::PAYMENT_PENDING,
        'status' => 'succeeded',
        'amount_received' => 15000,
        'payer' => [
            'name' => 'María Ejemplo',
            'tax_id' => '27123456781',
            'account' => '0000123300602983525937',
        ],
        'paid_at' => '2026-10-24T14:12:09Z',
    ];

    public const PAYMENT_REFUNDED = [
        ...self::PAYMENT_SUCCEEDED,
        'status' => 'refunded',
        'refunded_at' => '2026-10-24T15:30:00Z',
    ];

    public const EVENT_PAYMENT_SUCCEEDED = [
        'id' => 'evt_4mQ8sT2vX7bN1kR5wZ9cH3jL',
        'object' => 'event',
        'type' => 'payment.succeeded',
        'api_version' => '2026-10-23',
        'created_at' => '2026-10-24T14:12:09Z',
        'data' => ['object' => self::PAYMENT_SUCCEEDED],
    ];

    // Vectores fijos de la firma (raya-api webhooks/tests_crypto.py y api.md).
    public const WEBHOOK_TEST_BODY = '{"id":"evt_test","object":"event","type":"webhook.test"}';
    public const WEBHOOK_TEST_TIMESTAMP = 1700000000;
    public const WEBHOOK_SECRET = 'whsec_test_5f2b8c1e';
    public const WEBHOOK_SIGNATURE
        = '2d8099cd1fe615acd8a181903c2478d2526fc47c784558a9c4e512619c4e60d8';
    public const WEBHOOK_OLD_SECRET = 'whsec_old_0a9d';
    public const WEBHOOK_OLD_SIGNATURE
        = '8e449e40286c1e331a636dd9e38ab4a548c3c9a09733fa05205d7a10623ecc45';

    private function __construct() {}

    /**
     * Una página de transferencias (copias de TRANSFER_INBOUND con esos ids), como transfer_page()
     * en Python: has_more es true solo si hay next_cursor.
     *
     * @param list<string> $ids
     * @return array{
     *     object: 'list',
     *     data: list<array<string, mixed>>,
     *     has_more: bool,
     *     next_cursor: string|null,
     * }
     */
    public static function transferPage(array $ids, ?string $nextCursor): array
    {
        return [
            'object' => 'list',
            'data' => array_map(
                static fn(string $id): array => [...self::TRANSFER_INBOUND, 'id' => $id],
                $ids,
            ),
            'has_more' => $nextCursor !== null,
            'next_cursor' => $nextCursor,
        ];
    }
}
