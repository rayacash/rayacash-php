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

    // --- Apps y cuentas conectadas (api.md › Checkout y Usuarios) ----------------------------

    // La app de ejemplo (Tienda Ejemplo), la de utils/apiReferenceRequests.ts de raya-web. El
    // client_secret es sintético, partido en dos como las claves.
    public const CLIENT_ID = '7650337728110670';
    public const CLIENT_SECRET
        = 'raya_cs_live_' . 'TestSecret0123456789abcdefghijklmnopqrstuvwxyzAB';
    public const CODE = 'c0d3ejemploQx9Km2Pr4Tv6Wy8Za1Bc3De5Fg7Hj0Kn';
    public const REDIRECT_URI = 'https://tienda.example.com/raya/callback';
    public const NOTIFICATION_URL = 'we_3fN8kQ2vX7mB1pR5tZ9cW4yD';

    // La cuenta conectada de los ejemplos y su access_token (sintético: 48 caracteres base62).
    public const USER_ID = '9d4f2b7e-3c61-4e0a-9b8f-2a5c7d1e6f40';
    public const ACCESS_TOKEN
        = 'raya_at_live_' . '4mQ8sT2vX7bN1kR5wZ9cH3jLpD6fG0aEyU8iO2pA5sD7fG9hJk';

    public const OAUTH_TOKEN = [
        'object' => 'oauth_token',
        'access_token' => self::ACCESS_TOKEN,
        'token_type' => 'Bearer',
        'scope' => 'checkout profile',
        'user_id' => self::USER_ID,
        'expires_in' => null,
        'refresh_token' => null,
    ];

    // Una cuenta personal verificada, con su negocio configurado.
    public const USER = [
        'id' => self::USER_ID,
        'object' => 'user',
        'type' => 'personal',
        'email' => 'lucia@almacen.example.com',
        'first_name' => 'Lucía',
        'last_name' => 'Ejemplo',
        'full_name' => 'Lucía Ejemplo',
        'identification' => ['type' => 'DNI', 'number' => '34567890'],
        'tax_id' => '27345678901',
        'phone' => '+5493415550123',
        'date_of_birth' => '1990-05-17',
        'sex' => 'F',
        'nationality' => 'Argentina',
        'address' => [
            'street' => 'Calle Ejemplo',
            'number' => '1234',
            'floor' => '2',
            'apartment' => 'B',
            'city' => 'Rosario',
            'province' => 'Santa Fe',
            'postal_code' => '2000',
            'country' => 'Argentina',
        ],
        'identity_verified' => true,
        'verified_at' => '2026-10-24T15:35:08Z',
        'business' => null,
        'merchant' => ['name' => 'Almacén Ejemplo', 'image' => null],
        'created_at' => '2026-10-24T15:30:12Z',
    ];

    // La misma cuenta como empresa (el CUIT, con el verificador cambiado a propósito).
    public const USER_BUSINESS = [
        ...self::USER,
        'type' => 'business',
        'identification' => ['type' => 'CUIT', 'number' => '30345678907'],
        'tax_id' => '30345678907',
        'business' => [
            'legal_name' => 'Almacén Ejemplo S.R.L.',
            'tax_id' => '30345678907',
            'company_type' => 'S.R.L.',
            'representative' => [
                'first_name' => 'Lucía',
                'last_name' => 'Ejemplo',
                'tax_id' => '27345678901',
            ],
        ],
    ];

    // El checkout de ejemplo: un ítem de 45000 con una comisión de 500 para la app; a la cuenta
    // conectada le quedan 44500. Su id es el del pago.
    public const CHECKOUT_ID = 'e7b3a9c2-4d1f-4a8e-b6c0-5f2d9e8a7b61';

    public const CHECKOUT_PENDING = [
        'id' => self::CHECKOUT_ID,
        'object' => 'checkout',
        'status' => 'pending',
        'amount' => 45000,
        'fee' => 500,
        'net_amount' => 44500,
        'amount_received' => null,
        'currency' => 'ARS',
        'items' => [['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000]],
        'description' => 'Pedido 1042',
        'external_reference' => 'pedido-1042',
        'account' => self::USER_ID,
        'bank_transfer' => ['cvu' => '0000123390456538582824', 'alias' => 'raya.cash.ejemplo'],
        'checkout_url' => 'https://raya.cash/checkout/' . self::CHECKOUT_ID,
        'back_urls' => [
            'success' => 'https://tienda.example.com/pedidos/1042',
            'failure' => 'https://tienda.example.com/pedidos/1042/error',
        ],
        'auto_return' => 'approved',
        'notification_url' => self::NOTIFICATION_URL,
        'payer' => null,
        'failure_code' => null,
        'metadata' => ['pedido_id' => '1042'],
        'expires_at' => '2026-10-25T16:00:00Z',
        'paid_at' => null,
        'refunded_at' => null,
        'created_at' => '2026-10-24T16:00:00Z',
    ];

    public const CHECKOUT_SUCCEEDED = [
        ...self::CHECKOUT_PENDING,
        'status' => 'succeeded',
        'amount_received' => 45000,
        'payer' => [
            'name' => 'María Ejemplo',
            'tax_id' => '27123456781',
            'account' => '0000123300602983525937',
        ],
        'paid_at' => '2026-10-24T16:07:41Z',
    ];

    public const CHECKOUT_REFUNDED = [
        ...self::CHECKOUT_SUCCEEDED,
        'status' => 'refunded',
        'refunded_at' => '2026-10-24T17:20:00Z',
    ];

    public const EVENT_CHECKOUT_SUCCEEDED = [
        'id' => 'evt_7kP2mW9xB4nQ1rT6vZ3cH8jL',
        'object' => 'event',
        'type' => 'checkout.succeeded',
        'api_version' => '2026-10-23',
        'created_at' => '2026-10-24T16:07:41Z',
        'data' => ['object' => self::CHECKOUT_SUCCEEDED],
    ];

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
