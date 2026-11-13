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

    // --- Raya Connect (api.md › Connect y Usuarios) ------------------------------------------

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
        'scope' => 'orders profile',
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

    // El pago de Connect de ejemplo: un ítem de 45000 con una comisión de 500 para la app; a la
    // cuenta conectada le quedan 44500. Su id es el de su pago (Payment). checkout_url sigue siendo
    // la página de pago, raya.cash/checkout/<id>.
    public const CONNECT_ORDER_ID = 'e7b3a9c2-4d1f-4a8e-b6c0-5f2d9e8a7b61';

    public const CONNECT_ORDER_PENDING = [
        'id' => self::CONNECT_ORDER_ID,
        'object' => 'connect.order',
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
        'checkout_url' => 'https://raya.cash/checkout/' . self::CONNECT_ORDER_ID,
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

    public const CONNECT_ORDER_SUCCEEDED = [
        ...self::CONNECT_ORDER_PENDING,
        'status' => 'succeeded',
        'amount_received' => 45000,
        'payer' => [
            'name' => 'María Ejemplo',
            'tax_id' => '27123456781',
            'account' => '0000123300602983525937',
        ],
        'paid_at' => '2026-10-24T16:07:41Z',
    ];

    public const CONNECT_ORDER_REFUNDED = [
        ...self::CONNECT_ORDER_SUCCEEDED,
        'status' => 'refunded',
        'refunded_at' => '2026-10-24T17:20:00Z',
    ];

    // El payout de ejemplo: 60000 salen de tu saldo, 870.25 de comisión y el vendedor recibe
    // 59129.75. Su id es también el de la transferencia que lo representa; las cuentas son las
    // de los ejemplos de /api (demoCvu de raya-web, con los dígitos verificadores inválidos).
    public const CONNECT_PAYOUT_ID = '4f6a1d8b-2e37-4c95-8a10-7b3e9d2c5f14';

    public const CONNECT_PAYOUT_PENDING = [
        'id' => self::CONNECT_PAYOUT_ID,
        'object' => 'connect.payout',
        'status' => 'pending',
        'amount' => 60000,
        'processing_fee' => 870.25,
        'net_amount' => 59129.75,
        'currency' => 'ARS',
        'origin' => [
            'account' => '0000123368198805021689',
            'alias' => 'raya.cash.ejemplo',
            'name' => 'Tienda Ejemplo',
        ],
        'counterparty' => [
            'name' => 'Ana Ejemplo',
            'tax_id' => '20345678902',
            'account' => '0000123305838495183140',
            'alias' => 'ana.ejemplo',
        ],
        'reference' => null,
        'created_at' => '2026-10-24T17:40:06Z',
    ];

    public const CONNECT_PAYOUT_COMPLETED = [
        ...self::CONNECT_PAYOUT_PENDING,
        'status' => 'completed',
        'reference' => 'M7Q2X9T4K1V6N3B8R5W0ZD',
    ];

    public const CONNECT_PAYOUT_LIST = [
        'object' => 'list',
        'data' => [self::CONNECT_PAYOUT_COMPLETED, self::CONNECT_PAYOUT_PENDING],
        'has_more' => false,
        'next_cursor' => null,
    ];

    public const EVENT_CONNECT_ORDER_SUCCEEDED = [
        'id' => 'evt_7kP2mW9xB4nQ1rT6vZ3cH8jL',
        'object' => 'event',
        'type' => 'connect.order.succeeded',
        'api_version' => '2026-10-23',
        'created_at' => '2026-10-24T16:07:41Z',
        'data' => ['object' => self::CONNECT_ORDER_SUCCEEDED],
    ];

    // --- Identidad (api.md › Identidad) -----------------------------------------------------

    // Las verificaciones de ejemplo de raya.cash/api/identity, con datos sintéticos (las de
    // fixtures.ts del SDK de servidor de Identidad). Los montos son ilustrativos: nunca una
    // tarifa real.
    public const VERIFICATION_ID = 'idv_3kT9vQ2mXw7Lp0aBcD4eF6gH';
    public const BIOMETRIC_VERIFICATION_ID = 'idv_8Zr2Nf6Kq1Yc5Wd9Ht3Jm7Pa';

    public const VERIFICATION_PENDING = [
        'id' => self::VERIFICATION_ID,
        'object' => 'identity.verification',
        'type' => 'document',
        'status' => 'pending',
        'url' => 'https://raya.cash/identity/' . self::VERIFICATION_ID,
        'external_reference' => 'cliente-8812',
        'metadata' => ['plan' => 'pro'],
        'return_url' => 'https://tienda.example.com/cuenta',
        'reference' => null,
        'decline_reason' => null,
        'checks' => [
            'document' => ['status' => 'pending'],
            'liveness' => ['status' => 'pending', 'confidence' => null],
            'face_match' => ['status' => 'pending', 'similarity' => null],
            'registry' => ['status' => 'pending', 'data_match' => null, 'face_match' => null],
        ],
        'person' => null,
        'amount' => null,
        'currency' => 'ARS',
        'created_at' => '2026-10-24T18:00:00Z',
        'started_at' => null,
        'submitted_at' => null,
        'completed_at' => null,
        'expires_at' => '2026-10-25T18:00:00Z',
    ];

    public const VERIFICATION_PERSON = [
        'first_name' => 'María Laura',
        'last_name' => 'Ejemplo',
        'document_type' => 'DNI',
        'document_number' => '12345678',
        'sex' => 'F',
        'birth_date' => '1990-01-31',
        'nationality' => 'Argentina',
        'cuil' => '27123456781',
        'address' => [
            'street' => 'Calle Ejemplo',
            'number' => '1234',
            'floor' => null,
            'apartment' => null,
            'city' => 'Rosario',
            'municipality' => 'Rosario',
            'province' => 'Santa Fe',
            'postal_code' => '2000',
            'country' => 'Argentina',
        ],
        'document' => ['copy' => 'A', 'issue_date' => '2020-01-31', 'expiry_date' => '2035-01-31'],
    ];

    public const VERIFICATION_VERIFIED = [
        ...self::VERIFICATION_PENDING,
        'status' => 'verified',
        'checks' => [
            'document' => ['status' => 'passed'],
            'liveness' => ['status' => 'passed', 'confidence' => 99.1],
            'face_match' => ['status' => 'passed', 'similarity' => 97.4],
            'registry' => ['status' => 'passed', 'data_match' => true, 'face_match' => 'hit'],
        ],
        'person' => self::VERIFICATION_PERSON,
        'amount' => 1000,
        'started_at' => '2026-10-24T18:07:12Z',
        'submitted_at' => '2026-10-24T18:09:40Z',
        'completed_at' => '2026-10-24T18:09:58Z',
    ];

    public const VERIFICATION_DECLINED = [
        ...self::VERIFICATION_PENDING,
        'status' => 'declined',
        'decline_reason' => [
            'code' => 'registry_data_mismatch',
            'message' => 'Los datos del documento no coinciden con los del Registro Nacional de '
                . 'las Personas (RENAPER).',
        ],
        'checks' => [
            'document' => ['status' => 'passed'],
            'liveness' => ['status' => 'passed', 'confidence' => 99.1],
            'face_match' => ['status' => 'passed', 'similarity' => 97.4],
            'registry' => ['status' => 'failed', 'data_match' => false, 'face_match' => null],
        ],
        'started_at' => '2026-10-24T18:07:12Z',
        'submitted_at' => '2026-10-24T18:09:40Z',
        'completed_at' => '2026-10-24T18:09:58Z',
    ];

    public const VERIFICATION_CANCELED = [
        ...self::VERIFICATION_PENDING,
        'status' => 'canceled',
        'completed_at' => '2026-10-24T18:30:00Z',
    ];

    public const VERIFICATION_BIOMETRIC_VERIFIED = [
        ...self::VERIFICATION_VERIFIED,
        'id' => self::BIOMETRIC_VERIFICATION_ID,
        'type' => 'biometric',
        'url' => 'https://raya.cash/identity/' . self::BIOMETRIC_VERIFICATION_ID,
        'return_url' => null,
        'reference' => self::VERIFICATION_ID,
        'checks' => [
            'document' => ['status' => 'not_applicable'],
            'liveness' => ['status' => 'passed', 'confidence' => 99.1],
            'face_match' => ['status' => 'passed', 'similarity' => 98.2],
            'registry' => [
                'status' => 'not_applicable',
                'data_match' => null,
                'face_match' => null,
            ],
        ],
        'amount' => 400,
        'created_at' => '2026-10-24T20:00:00Z',
        'started_at' => '2026-10-24T20:02:10Z',
        'submitted_at' => '2026-10-24T20:03:05Z',
        'completed_at' => '2026-10-24T20:03:05Z',
        'expires_at' => '2026-10-25T20:00:00Z',
    ];

    public const VERIFICATION_NEXT_CURSOR
        = 'MjAyNi0xMC0yNFQxODowMDowMFp8aWR2XzNrVDl2UTJtWHc3THAwYUJjRDRlRjZnSA';

    public const VERIFICATION_LIST = [
        'object' => 'list',
        'data' => [self::VERIFICATION_BIOMETRIC_VERIFIED, self::VERIFICATION_VERIFIED],
        'has_more' => true,
        'next_cursor' => self::VERIFICATION_NEXT_CURSOR,
    ];

    public const VERIFICATION_FILES = [
        'object' => 'identity.verification_files',
        'front' => [
            'url' => 'https://files.example.com/' . self::VERIFICATION_ID
                . '/front.jpg?signature=ejemplo',
            'expires_at' => '2026-10-24T18:25:00Z',
        ],
        'back' => [
            'url' => 'https://files.example.com/' . self::VERIFICATION_ID
                . '/back.jpg?signature=ejemplo',
            'expires_at' => '2026-10-24T18:25:00Z',
        ],
        'selfie' => [
            'url' => 'https://files.example.com/' . self::VERIFICATION_ID
                . '/selfie.jpg?signature=ejemplo',
            'expires_at' => '2026-10-24T18:25:00Z',
        ],
    ];

    // Los eventos traen la verificación siempre con person en null, y livemode en true, como todo
    // evento de una cuenta real.
    public const EVENT_VERIFICATION_VERIFIED = [
        'id' => 'evt_7cW2pL9xT4bN6kR1mZ8vH3jQ',
        'object' => 'event',
        'type' => 'identity.verification.verified',
        'api_version' => '2026-10-23',
        'created_at' => '2026-10-24T18:09:58Z',
        'livemode' => true,
        'data' => ['object' => [...self::VERIFICATION_VERIFIED, 'person' => null]],
    ];

    public const EVENT_VERIFICATION_CANCELED = [
        'id' => 'evt_2nK8fR5yW3cV7qL1pX9dG4sM',
        'object' => 'event',
        'type' => 'identity.verification.canceled',
        'api_version' => '2026-10-23',
        'created_at' => '2026-10-24T18:30:00Z',
        'livemode' => true,
        'data' => ['object' => self::VERIFICATION_CANCELED],
    ];

    // --- Sandbox (api.md › Sandbox) ---------------------------------------------------------

    // Los de tests/fixtures_sandbox.py de rayacash-python: la forma de las respuestas reales de
    // la API a un pago de prueba. El CVU de prueba (00000002…, 22 dígitos que no son de ninguna
    // cuenta), el alias sandbox.no.transferir, checkout_url en /sandbox/pay/<id> y el pagador de
    // prueba, siempre el mismo. Los valores son sintéticos, como los demás.

    // Las credenciales de prueba de un sandbox: el prefijo de prueba y 48 caracteres base62. La
    // clave secreta de prueba y el access_token de un usuario de prueba (el que devuelve
    // oauth->token con el client_secret de prueba de una app del sandbox).
    public const SANDBOX_KEY = 'raya_sk_test_' . '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKL';
    public const SANDBOX_ACCESS_TOKEN
        = 'raya_at_test_' . 'z4Vw1Np6Hd8Jf5GcpR5tZ9cW4yD3fN8kQ2vX7mB1hJ6gL0sA';

    public const SANDBOX_ALIAS = 'sandbox.no.transferir';
    public const SANDBOX_PAYER = [
        'name' => 'Pagador de prueba',
        'tax_id' => '20000000001',
        'account' => '0000000200000000000001',
    ];

    public const SANDBOX_PAYMENT_ID = '5d2c8e14-7a3b-4f69-9e05-b1c4d7a2f830';

    // Recién creado con payments->create y la clave de prueba: el CVU de prueba del sandbox, vence
    // a las 24 horas.
    public const SANDBOX_PAYMENT_PENDING = [
        'id' => self::SANDBOX_PAYMENT_ID,
        'object' => 'payment',
        'status' => 'pending',
        'amount' => 2500,
        'amount_received' => null,
        'currency' => 'ARS',
        'description' => 'Pedido 1043',
        'external_reference' => 'pedido-1043',
        'bank_transfer' => ['cvu' => '0000000271940365218453', 'alias' => self::SANDBOX_ALIAS],
        'checkout_url' => 'https://raya.cash/sandbox/pay/' . self::SANDBOX_PAYMENT_ID,
        'return_url' => null,
        'payer' => null,
        'failure_code' => null,
        'metadata' => [],
        'expires_at' => '2026-11-12T14:42:33Z',
        'paid_at' => null,
        'refunded_at' => null,
        'created_at' => '2026-11-11T14:42:34Z',
    ];

    // Simulado sin amount: el monto exacto.
    public const SANDBOX_PAYMENT_SUCCEEDED = [
        ...self::SANDBOX_PAYMENT_PENDING,
        'status' => 'succeeded',
        'amount_received' => 2500,
        'payer' => self::SANDBOX_PAYER,
        'paid_at' => '2026-11-11T14:43:02Z',
    ];

    // Simulado con menos (amount 1000): failed con amount_mismatch y sin paid_at. En un sandbox no
    // se devuelve solo (en la cuenta real, sí: pasa a refunded).
    public const SANDBOX_PAYMENT_FAILED = [
        ...self::SANDBOX_PAYMENT_PENDING,
        'status' => 'failed',
        'amount_received' => 1000,
        'payer' => self::SANDBOX_PAYER,
        'failure_code' => 'amount_mismatch',
    ];

    // Simulado con más (amount 4000): succeeded, con amount_received en lo simulado.
    public const SANDBOX_PAYMENT_OVERPAID = [
        ...self::SANDBOX_PAYMENT_SUCCEEDED,
        'amount_received' => 4000,
    ];

    // Un pago de Raya Connect de una app del sandbox, a nombre de un usuario de prueba, simulado
    // con la clave secreta de prueba de la cuenta dueña de la app: el connect.order de siempre,
    // con el CVU de prueba del usuario de prueba: a él le quedan 1490 y a la app, 10.
    public const SANDBOX_ORDER_ID = '8a1f3c5e-2d47-4b96-a0e8-6c9b7d3e1f52';
    public const SANDBOX_TEST_USER_ID = '3e9b6a20-5c1d-4f87-8b3a-d42e7f0c9a16';

    public const SANDBOX_ORDER_SUCCEEDED = [
        'id' => self::SANDBOX_ORDER_ID,
        'object' => 'connect.order',
        'status' => 'succeeded',
        'amount' => 1500,
        'fee' => 10,
        'net_amount' => 1490,
        'amount_received' => 1500,
        'currency' => 'ARS',
        'items' => [['title' => 'Pedido 7', 'quantity' => 1, 'unit_price' => 1500]],
        'description' => 'Pedido 7',
        'external_reference' => null,
        'account' => self::SANDBOX_TEST_USER_ID,
        'bank_transfer' => ['cvu' => '0000000238516094726185', 'alias' => self::SANDBOX_ALIAS],
        'checkout_url' => 'https://raya.cash/checkout/' . self::SANDBOX_ORDER_ID,
        'back_urls' => ['success' => null, 'failure' => null],
        'auto_return' => null,
        'notification_url' => null,
        'payer' => self::SANDBOX_PAYER,
        'failure_code' => null,
        'metadata' => [],
        'expires_at' => '2026-11-12T14:42:36Z',
        'paid_at' => '2026-11-11T14:42:36Z',
        'refunded_at' => null,
        'created_at' => '2026-11-11T14:42:36Z',
    ];

    // El evento de un sandbox: livemode en false, entre created_at y data (el orden del sobre). Los
    // de la cuenta real lo traen en true, y los creados antes de que la API lo sumara no lo traen
    // (EVENT_PAYMENT_SUCCEEDED).
    public const EVENT_SANDBOX_PAYMENT_SUCCEEDED = [
        'id' => 'evt_9sB3nQ7wK2xV5mR8tL1cZ4hJ',
        'object' => 'event',
        'type' => 'payment.succeeded',
        'api_version' => '2026-10-23',
        'created_at' => '2026-11-11T14:43:02Z',
        'livemode' => false,
        'data' => ['object' => self::SANDBOX_PAYMENT_SUCCEEDED],
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

    /**
     * Una página de verificaciones (copias de VERIFICATION_VERIFIED con esos ids), como
     * verificationPage() en el SDK de servidor de Identidad.
     *
     * @param list<string> $ids
     * @return array{
     *     object: 'list',
     *     data: list<array<string, mixed>>,
     *     has_more: bool,
     *     next_cursor: string|null,
     * }
     */
    public static function verificationPage(array $ids, ?string $nextCursor): array
    {
        return [
            'object' => 'list',
            'data' => array_map(
                static fn(string $id): array => [...self::VERIFICATION_VERIFIED, 'id' => $id],
                $ids,
            ),
            'has_more' => $nextCursor !== null,
            'next_cursor' => $nextCursor,
        ];
    }
}
