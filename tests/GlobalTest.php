<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\ConnectOrder;
use RayaCash\ConnectPayout;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\IdentityVerification;
use RayaCash\IdentityVerificationFiles;
use RayaCash\Internal\Transport;
use RayaCash\Invoice;
use RayaCash\ListObject;
use RayaCash\OAuth;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\PspBalance;
use RayaCash\PspCustomer;
use RayaCash\PspCustomerBalance;
use RayaCash\PspTransfer;
use RayaCash\RayaCash;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\MockApi;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\User;
use RayaCash\Util\LoggerInterface;
use RayaCash\Webhook;
use RayaCash\WebhookSignature;

/**
 * El estilo global: RayaCash::setApiKey(…) y después Payment::create(…) (test_global.py, con los
 * extras de global.test.ts). Cada test arranca con RayaCash::reset() (Support\TestCase).
 *
 * Por diseño, en PHP:
 * - el reembolso por id es Payment::refundById($id) (Payment.refund(id) en Python y Node): PHP
 *   no deja tener un refund() estático y otro del pago con el mismo nombre;
 * - cada setter valida al asignar (Python y Node fallan recién en la request), salvo la clave,
 *   que se valida en cada request.
 */
final class GlobalTest extends TestCase
{
    /** Los valores de fábrica del estilo global (contrato común de los SDKs, invariantes 4 y 5). */
    private const FACTORY = [
        'api_base' => 'https://api.raya.cash',
        'timeout' => 65,
        'max_network_retries' => 2,
        'http_client' => null,
        'app_info' => null,
        'log' => null,
        'logger' => null,
    ];

    private const TOKEN_PARAMS = [
        'client_id' => Fixtures::CLIENT_ID,
        'client_secret' => Fixtures::CLIENT_SECRET,
        'code' => Fixtures::CODE,
        'redirect_uri' => Fixtures::REDIRECT_URI,
    ];

    private const ITEM = ['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000];

    /** @var list<HttpClientInterface> Los clientes HTTP propios que creó el SDK. */
    private array $created = [];

    public function testEveryEndpoint(): void
    {
        $api = $this->globalApi();
        $api->add(200, Fixtures::BALANCE);
        $balance = Balance::retrieve();
        self::assertSame(Balance::class, $balance::class);
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $api->last()->header('authorization'));

        $api->add(201, Fixtures::TRANSFER_OUTBOUND_PENDING);
        $transfer = Transfer::create(
            ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
            ['idempotency_key' => 'k'],
        );
        self::assertSame(Transfer::class, $transfer::class);
        self::assertSame('k', $api->last()->header('idempotency-key'));

        $api->add(200, Fixtures::TRANSFER_INBOUND);
        $transfer = Transfer::retrieve(Fixtures::TRANSFER_INBOUND['id']);
        self::assertSame('inbound', $transfer->direction);

        $api->add(200, Fixtures::TRANSFER_LIST);
        $page = Transfer::list(['direction' => 'inbound', 'limit' => 2]);
        self::assertSame(ListObject::class, $page::class);
        self::assertSame('2', $api->last()->query['limit']);

        $api->add(201, Fixtures::PAYMENT_PENDING);
        $payment = Payment::create(['amount' => 15000, 'metadata' => ['cliente_id' => '8812']]);
        self::assertSame(Payment::class, $payment::class);
        self::assertSame(
            ['amount' => 15000, 'metadata' => ['cliente_id' => '8812']],
            $api->lastJson(),
        );

        $api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        self::assertSame('succeeded', Payment::retrieve(Fixtures::PAYMENT_ID)->status);

        // Payment.refund(id) en Python y Node; acá, Payment::refundById($id).
        $api->add(200, Fixtures::PAYMENT_REFUNDED);
        $refunded = Payment::refundById(Fixtures::PAYMENT_ID, ['idempotency_key' => 'r-1']);
        self::assertSame('refunded', $refunded->status);
        self::assertSame('/v1/payments/' . Fixtures::PAYMENT_ID . '/refund', $api->last()->path);
        self::assertSame('r-1', $api->last()->header('idempotency-key'));

        // Los payouts de Raya Connect, con la clave secreta, como las transferencias.
        $api->add(201, Fixtures::CONNECT_PAYOUT_PENDING);
        $payout = ConnectPayout::create(
            ['amount' => 60000, 'destination' => 'ana.ejemplo'],
            ['idempotency_key' => 'p-1'],
        );
        self::assertSame(ConnectPayout::class, $payout::class);
        self::assertSame(['amount' => 60000, 'destination' => 'ana.ejemplo'], $api->lastJson());
        self::assertSame('p-1', $api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());

        $api->add(200, Fixtures::CONNECT_PAYOUT_COMPLETED);
        $payout = ConnectPayout::retrieve(Fixtures::CONNECT_PAYOUT_ID);
        self::assertSame('completed', $payout->status);
        self::assertSame('/v1/connect/payouts/' . Fixtures::CONNECT_PAYOUT_ID, $api->last()->path);

        $api->add(200, Fixtures::CONNECT_PAYOUT_LIST);
        $payouts = ConnectPayout::list(['status' => 'completed', 'limit' => 2]);
        self::assertSame(ListObject::class, $payouts::class);
        self::assertSame(ConnectPayout::class, $payouts->data[0]::class);
        self::assertSame('/v1/connect/payouts', $api->last()->path);
        self::assertSame(['status' => 'completed', 'limit' => '2'], $api->last()->query);

        // Identidad, con la clave secreta. IdentityVerification.cancel(id) en Python y Node; acá,
        // IdentityVerification::cancelById($id).
        $api->add(201, Fixtures::VERIFICATION_PENDING);
        $verification = IdentityVerification::create(
            ['type' => 'document', 'metadata' => ['plan' => 'pro']],
            ['idempotency_key' => 'v-1'],
        );
        self::assertSame(IdentityVerification::class, $verification::class);
        self::assertSame(['type' => 'document', 'metadata' => ['plan' => 'pro']], $api->lastJson());
        self::assertSame('v-1', $api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());

        $api->add(200, Fixtures::VERIFICATION_VERIFIED);
        $verification = IdentityVerification::retrieve(Fixtures::VERIFICATION_ID);
        self::assertSame('verified', $verification->status);
        self::assertSame(
            '/v1/identity/verifications/' . Fixtures::VERIFICATION_ID,
            $api->last()->path,
        );

        $api->add(200, Fixtures::VERIFICATION_LIST);
        $verifications = IdentityVerification::list(['status' => 'verified', 'limit' => 2]);
        self::assertSame(ListObject::class, $verifications::class);
        self::assertSame(IdentityVerification::class, $verifications->data[0]::class);
        self::assertSame(['status' => 'verified', 'limit' => '2'], $api->last()->query);

        $api->add(200, Fixtures::VERIFICATION_CANCELED);
        $canceled = IdentityVerification::cancelById(
            Fixtures::VERIFICATION_ID,
            ['idempotency_key' => 'c-1'],
        );
        self::assertSame('canceled', $canceled->status);
        self::assertSame(
            '/v1/identity/verifications/' . Fixtures::VERIFICATION_ID . '/cancel',
            $api->last()->path,
        );
        self::assertSame('c-1', $api->last()->header('idempotency-key'));

        $api->add(200, Fixtures::VERIFICATION_FILES);
        $files = IdentityVerification::files(Fixtures::VERIFICATION_ID);
        self::assertSame(IdentityVerificationFiles::class, $files::class);
        self::assertSame(
            '/v1/identity/verifications/' . Fixtures::VERIFICATION_ID . '/files',
            $api->last()->path,
        );

        // Raya Connect. OAuth::token no lleva Authorization: la clave global no viaja.
        $api->add(200, Fixtures::OAUTH_TOKEN);
        $token = OAuth::token(self::TOKEN_PARAMS);
        self::assertSame(OAuthToken::class, $token::class);
        self::assertNull($api->last()->header('authorization'));
        self::assertNull($api->last()->header('idempotency-key'));
        $body = $api->lastJson();
        self::assertIsArray($body);
        self::assertSame('authorization_code', $body['grant_type']);

        // Por la cuenta conectada: su access_token como clave global.
        RayaCash::setApiKey($token->access_token);
        $api->add(200, Fixtures::USER);
        $user = User::retrieve($token->user_id);
        self::assertSame(User::class, $user::class);
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $this->lastAuthorization());
        self::assertSame('/v1/users/' . Fixtures::USER_ID, $api->last()->path);

        $api->add(201, Fixtures::CONNECT_ORDER_PENDING);
        $connectOrder = ConnectOrder::create(['items' => [self::ITEM], 'fee' => 500]);
        self::assertSame(ConnectOrder::class, $connectOrder::class);
        self::assertSame(['items' => [self::ITEM], 'fee' => 500], $api->lastJson());
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $this->lastAuthorization());

        $api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        self::assertSame('succeeded', ConnectOrder::retrieve(Fixtures::CONNECT_ORDER_ID)->status);

        // ConnectOrder.refund(id) en Python y Node; acá, ConnectOrder::refundById($id).
        $api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $refunded = ConnectOrder::refundById(
            Fixtures::CONNECT_ORDER_ID,
            ['idempotency_key' => 'r-2'],
        );
        self::assertSame('refunded', $refunded->status);
        self::assertSame(
            '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund',
            $api->last()->path,
        );
        self::assertSame('r-2', $api->last()->header('idempotency-key'));
    }

    public function testConnectOrderInstanceRefund(): void
    {
        RayaCash::setApiKey(Fixtures::ACCESS_TOKEN);
        RayaCash::setHttpClient($this->api);
        $this->api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        $connectOrder = ConnectOrder::retrieve(Fixtures::CONNECT_ORDER_ID);
        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $refunded = $connectOrder->refund();
        self::assertSame(['refunded', 'succeeded'], [$refunded->status, $connectOrder->status]);
        self::assertSame(
            '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund',
            $this->api->last()->path,
        );
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $this->lastAuthorization());

        // Como con Payment: refundById() es estático y ConnectOrder::refund($id) es un Error del
        // motor.
        $error = self::assertThrows(
            \Error::class,
            // @phpstan-ignore method.staticCall, argument.type (como ConnectOrder.refund(id))
            static fn() => ConnectOrder::refund(Fixtures::CONNECT_ORDER_ID),
            'cannot be called statically',
        );
        self::assertSame(\Error::class, $error::class);
        $withoutId = [
            [],
            ['object' => 'connect.order', 'id' => ''],
            ['object' => 'connect.order', 'id' => 7],
        ];
        foreach ($withoutId as $values) {
            self::assertThrows(
                \ValueError::class,
                static fn() => ConnectOrder::constructFrom($values)->refund(),
                'Este ConnectOrder no tiene id: no se puede reembolsar.',
            );
        }
        self::assertCount(2, $this->api->requests);
    }

    /**
     * $verification->cancel() devuelve una verificación nueva y usa el cliente del que vino (o el
     * estilo global). Por id, con el estilo global, es IdentityVerification::cancelById(): PHP no
     * deja tener un cancel() estático y otro de instancia.
     */
    public function testIdentityVerificationInstanceCancel(): void
    {
        $this->globalApi();
        $this->api->add(200, Fixtures::VERIFICATION_PENDING);
        $verification = IdentityVerification::retrieve(Fixtures::VERIFICATION_ID);
        $this->api->add(200, Fixtures::VERIFICATION_CANCELED);
        $canceled = $verification->cancel();
        self::assertSame(['canceled', 'pending'], [$canceled->status, $verification->status]);
        self::assertSame(
            '/v1/identity/verifications/' . Fixtures::VERIFICATION_ID . '/cancel',
            $this->api->last()->path,
        );
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());

        // Con el cliente del que vino, no con el estilo global.
        $otherApi = new MockApi();
        $otherApi->add(200, Fixtures::VERIFICATION_PENDING);
        $fromClient = $otherApi->client(Fixtures::OTHER_KEY)->identity->verifications->retrieve(
            Fixtures::VERIFICATION_ID,
        );
        $otherApi->add(200, Fixtures::VERIFICATION_CANCELED);
        $fromClient->cancel(['idempotency_key' => 'c-2']);
        $request = $otherApi->last();
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $request->header('authorization'));
        self::assertSame('c-2', $request->header('idempotency-key'));
        self::assertCount(2, $this->api->requests);

        $error = self::assertThrows(
            \Error::class,
            // @phpstan-ignore method.staticCall, argument.type (IdentityVerification.cancel(id))
            static fn() => IdentityVerification::cancel(Fixtures::VERIFICATION_ID),
            'cannot be called statically',
        );
        self::assertSame(\Error::class, $error::class);
        $withoutId = [
            [],
            ['object' => 'identity.verification', 'id' => ''],
            ['object' => 'identity.verification', 'id' => null],
            ['object' => 'identity.verification', 'id' => 8812],
        ];
        foreach ($withoutId as $values) {
            self::assertThrows(
                \ValueError::class,
                static fn() => IdentityVerification::constructFrom($values)->cancel(),
                'Esta IdentityVerification no tiene id: no se puede cancelar.',
            );
        }
        self::assertCount(2, $this->api->requests);
    }

    /** Un evento de Identidad del estilo global: su verificación se cancela con la clave global. */
    public function testIdentityEventsFromWebhookConstructEventUseTheGlobalStyle(): void
    {
        $this->globalApi();
        $body = json_encode(Fixtures::EVENT_VERIFICATION_VERIFIED, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
        $event = Webhook::constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
        $verification = $event->data->object;
        self::assertInstanceOf(IdentityVerification::class, $verification);
        self::assertNull($verification->person);
        $this->api->add(200, Fixtures::VERIFICATION_CANCELED);
        $verification->cancel(['idempotency_key' => 'c-3']);
        self::assertSame('c-3', $this->api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
    }

    /**
     * Raya PSP con el estilo global y la clave secreta: cada estático, por su ruta. Por id, las
     * acciones son blockById(), unblockById(), closeById() y recreateById() (PspCustomer.block(id)
     * en Python y Node): PHP no deja tener un block() estático y otro de instancia.
     */
    public function testPspEndpointsWithTheGlobalStyle(): void
    {
        $api = $this->globalApi();
        $path = '/v1/psp/customers/' . Fixtures::PSP_CUSTOMER_ID;
        $api->add(201, Fixtures::PSP_CUSTOMER_CREATED);
        $pspCustomer = PspCustomer::create(
            ['identity_verification' => Fixtures::VERIFICATION_ID, 'metadata' => ['plan' => 'pro']],
            ['idempotency_key' => 'alta-1'],
        );
        self::assertSame(PspCustomer::class, $pspCustomer::class);
        self::assertSame(
            ['identity_verification' => Fixtures::VERIFICATION_ID, 'metadata' => ['plan' => 'pro']],
            $api->lastJson(),
        );
        self::assertSame('alta-1', $api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());

        $api->add(200, Fixtures::PSP_CUSTOMER_ACTIVE);
        self::assertSame('active', PspCustomer::retrieve(Fixtures::PSP_CUSTOMER_ID)->status);
        self::assertSame($path, $api->last()->path);

        $api->add(200, Fixtures::PSP_CUSTOMER_LIST);
        $customers = PspCustomer::list(['status' => 'active', 'limit' => 2]);
        self::assertSame(ListObject::class, $customers::class);
        self::assertSame(PspCustomer::class, $customers->data[0]::class);
        self::assertSame(['status' => 'active', 'limit' => '2'], $api->last()->query);

        $api->add(200, Fixtures::PSP_CUSTOMER_BALANCE);
        $customerBalance = PspCustomer::retrieveBalance(Fixtures::PSP_CUSTOMER_ID);
        self::assertSame(PspCustomerBalance::class, $customerBalance::class);
        self::assertSame($path . '/balance', $api->last()->path);

        $api->add(200, Fixtures::PSP_CUSTOMER_BLOCKED);
        $blocked = PspCustomer::blockById(Fixtures::PSP_CUSTOMER_ID, ['idempotency_key' => 'b-1']);
        self::assertSame('blocked', $blocked->status);
        self::assertSame(
            [$path . '/block', 'b-1'],
            [$api->last()->path, $api->last()->header('idempotency-key')],
        );
        $api->add(200, Fixtures::PSP_CUSTOMER_ACTIVE);
        self::assertSame('active', PspCustomer::unblockById(Fixtures::PSP_CUSTOMER_ID)->status);
        self::assertSame($path . '/unblock', $api->last()->path);
        $api->add(200, Fixtures::PSP_CUSTOMER_CLOSED);
        self::assertSame('closed', PspCustomer::closeById(Fixtures::PSP_CUSTOMER_ID)->status);
        self::assertSame($path . '/close', $api->last()->path);
        $api->add(202, Fixtures::PSP_CUSTOMER_PENDING);
        self::assertSame('pending', PspCustomer::recreateById(Fixtures::PSP_CUSTOMER_ID)->status);
        self::assertSame($path . '/recreate', $api->last()->path);
        self::assertNull($api->last()->body);

        $api->add(201, Fixtures::PSP_TRANSFER_OUTBOUND_PENDING);
        $pspTransfer = PspTransfer::create(
            [
                'customer' => Fixtures::PSP_CUSTOMER_ID,
                'amount' => 18350.5,
                'destination' => 'proveedor.ejemplo',
            ],
            ['idempotency_key' => 't-1'],
        );
        self::assertSame(PspTransfer::class, $pspTransfer::class);
        self::assertSame(
            [
                'customer' => Fixtures::PSP_CUSTOMER_ID,
                'amount' => 18350.5,
                'destination' => 'proveedor.ejemplo',
            ],
            $api->lastJson(),
        );
        self::assertSame('t-1', $api->last()->header('idempotency-key'));

        $api->add(200, Fixtures::PSP_TRANSFER_INBOUND);
        self::assertSame('inbound', PspTransfer::retrieve(Fixtures::PSP_INBOUND_ID)->direction);
        self::assertSame('/v1/psp/transfers/' . Fixtures::PSP_INBOUND_ID, $api->last()->path);

        $api->add(200, Fixtures::PSP_TRANSFER_LIST);
        $transfers = PspTransfer::list(['customer' => Fixtures::PSP_CUSTOMER_ID]);
        self::assertSame(PspTransfer::class, $transfers->data[0]::class);
        self::assertSame(['customer' => Fixtures::PSP_CUSTOMER_ID], $api->last()->query);

        $api->add(200, Fixtures::PSP_BALANCE);
        $pspBalance = PspBalance::retrieve();
        self::assertSame(PspBalance::class, $pspBalance::class);
        self::assertSame('/v1/psp/balance', $api->last()->path);
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
        self::assertCount(12, $api->requests);
    }

    /**
     * $pspCustomer->block(), unblock(), close() y recreate() devuelven un cliente nuevo y usan el
     * cliente del que vino (o el estilo global).
     */
    public function testPspCustomerInstanceActions(): void
    {
        $this->globalApi();
        $path = '/v1/psp/customers/' . Fixtures::PSP_CUSTOMER_ID;
        $this->api->add(200, Fixtures::PSP_CUSTOMER_ACTIVE);
        $pspCustomer = PspCustomer::retrieve(Fixtures::PSP_CUSTOMER_ID);
        $this->api->add(200, Fixtures::PSP_CUSTOMER_BLOCKED);
        $blocked = $pspCustomer->block();
        self::assertSame(['blocked', 'active'], [$blocked->status, $pspCustomer->status]);
        self::assertSame($path . '/block', $this->api->last()->path);
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
        $this->api->add(200, Fixtures::PSP_CUSTOMER_ACTIVE);
        self::assertSame('active', $blocked->unblock(['idempotency_key' => 'u-1'])->status);
        self::assertSame($path . '/unblock', $this->api->last()->path);
        self::assertSame('u-1', $this->api->last()->header('idempotency-key'));
        $this->api->add(200, Fixtures::PSP_CUSTOMER_CLOSED);
        $closed = $pspCustomer->close();
        self::assertSame($path . '/close', $this->api->last()->path);
        $this->api->add(202, Fixtures::PSP_CUSTOMER_PENDING);
        self::assertSame('pending', $closed->recreate()->status);
        self::assertSame($path . '/recreate', $this->api->last()->path);
        self::assertNull($this->api->last()->body);

        // Con el cliente del que vino, no con el estilo global.
        $otherApi = new MockApi();
        $otherApi->add(200, Fixtures::PSP_CUSTOMER_ACTIVE);
        $fromClient = $otherApi->client(Fixtures::OTHER_KEY)->psp->customers->retrieve(
            Fixtures::PSP_CUSTOMER_ID,
        );
        $otherApi->add(200, Fixtures::PSP_CUSTOMER_BLOCKED);
        $fromClient->block(['idempotency_key' => 'b-2']);
        $request = $otherApi->last();
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $request->header('authorization'));
        self::assertSame('b-2', $request->header('idempotency-key'));
        self::assertSame($path . '/block', $request->path);
        self::assertCount(5, $this->api->requests);

        $error = self::assertThrows(
            \Error::class,
            // @phpstan-ignore method.staticCall, argument.type (como PspCustomer.block(id))
            static fn() => PspCustomer::block(Fixtures::PSP_CUSTOMER_ID),
            'cannot be called statically',
        );
        self::assertSame(\Error::class, $error::class);
        $actions = [
            'bloquear' => static fn(PspCustomer $customer): mixed => $customer->block(),
            'desbloquear' => static fn(PspCustomer $customer): mixed => $customer->unblock(),
            'dar de baja su CVU' => static fn(PspCustomer $customer): mixed => $customer->close(),
            'recrear su CVU' => static fn(PspCustomer $customer): mixed => $customer->recreate(),
        ];
        $withoutId = [
            [],
            ['object' => 'psp.customer', 'id' => ''],
            ['object' => 'psp.customer', 'id' => null],
            ['object' => 'psp.customer', 'id' => 8812],
        ];
        foreach ($actions as $what => $action) {
            foreach ($withoutId as $values) {
                self::assertThrows(
                    \ValueError::class,
                    static fn() => $action(PspCustomer::constructFrom($values)),
                    "Este PspCustomer no tiene id: no se puede {$what}.",
                );
            }
        }
        self::assertCount(5, $this->api->requests);
    }

    /** Un evento de Raya PSP del estilo global: su cliente se bloquea con la clave global. */
    public function testPspEventsFromWebhookConstructEventUseTheGlobalStyle(): void
    {
        $this->globalApi();
        $body = json_encode(Fixtures::EVENT_PSP_CUSTOMER_ACTIVATED, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
        $event = Webhook::constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
        $pspCustomer = $event->data->object;
        self::assertInstanceOf(PspCustomer::class, $pspCustomer);
        $this->api->add(200, Fixtures::PSP_CUSTOMER_BLOCKED);
        $pspCustomer->block(['idempotency_key' => 'b-3']);
        self::assertSame(
            '/v1/psp/customers/' . Fixtures::PSP_CUSTOMER_ID . '/block',
            $this->api->last()->path,
        );
        self::assertSame('b-3', $this->api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
    }

    /**
     * El Facturador con el estilo global y la clave secreta: Invoice::create() (con la clave de
     * idempotencia obligatoria, que también se pide antes de mandar nada), retrieve() y list().
     */
    public function testInvoiceEndpointsWithTheGlobalStyle(): void
    {
        $api = $this->globalApi();
        $api->add(202, Fixtures::INVOICE_PENDING);
        $invoice = Invoice::create(
            ['dni' => Fixtures::INVOICE_DNI, 'amount' => 15000, 'metadata' => ['pedido' => '1042']],
            ['idempotency_key' => 'venta-1042'],
        );
        self::assertSame(Invoice::class, $invoice::class);
        self::assertSame(Invoice::STATUS_PENDING, $invoice->status);
        self::assertSame(
            ['dni' => '12345678', 'amount' => 15000, 'metadata' => ['pedido' => '1042']],
            $api->lastJson(),
        );
        self::assertSame(['POST', '/v1/invoices'], [$api->last()->method, $api->last()->path]);
        self::assertSame('venta-1042', $api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());

        $api->add(200, Fixtures::INVOICE_ISSUED);
        $issued = Invoice::retrieve(Fixtures::INVOICE_ID);
        self::assertSame(
            [Invoice::STATUS_ISSUED, Fixtures::INVOICE_CAE],
            [$issued->status, $issued->cae],
        );
        self::assertSame('/v1/invoices/' . Fixtures::INVOICE_ID, $api->last()->path);

        $api->add(200, Fixtures::INVOICE_LIST);
        $page = Invoice::list(['status' => 'issued', 'limit' => 2]);
        self::assertSame(ListObject::class, $page::class);
        self::assertSame(Invoice::class, $page->data[0]::class);
        self::assertSame(['status' => 'issued', 'limit' => '2'], $api->last()->query);

        // Con la clave de otra cuenta, por llamada.
        $api->add(200, Fixtures::INVOICE_ISSUED);
        Invoice::retrieve(Fixtures::INVOICE_ID, ['api_key' => Fixtures::OTHER_KEY]);
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->lastAuthorization());

        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => Invoice::create(['dni' => Fixtures::INVOICE_DNI, 'amount' => 1], []),
            'invoices->create necesita idempotency_key',
        );
        self::assertCount(4, $api->requests);
    }

    public function testInstanceRefund(): void
    {
        $this->globalApi();
        $this->api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = Payment::retrieve(Fixtures::PAYMENT_ID);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $refunded = $payment->refund();
        self::assertSame('refunded', $refunded->status);
        self::assertSame('succeeded', $payment->status); // devuelve un pago nuevo, no muta este
        self::assertSame(
            '/v1/payments/' . Fixtures::PAYMENT_ID . '/refund',
            $this->api->last()->path,
        );
    }

    public function testInstanceRefundUsesTheClientItCameFrom(): void
    {
        $this->globalApi();
        $otherApi = new MockApi();
        $client = $otherApi->client(Fixtures::OTHER_KEY);
        $otherApi->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        $otherApi->add(200, Fixtures::PAYMENT_REFUNDED);
        $payment->refund();
        self::assertSame(
            'Bearer ' . Fixtures::OTHER_KEY,
            $otherApi->last()->header('authorization'),
        );
        self::assertSame([], $this->api->requests);
    }

    /** De PHP (Q4 del diseño): las opciones de la llamada pisan las del cliente del pago. */
    public function testInstanceRefundOptionsWinOverItsClient(): void
    {
        $this->globalApi();
        $otherApi = new MockApi();
        $otherApi->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $otherApi->client(Fixtures::OTHER_KEY)->payments->retrieve(Fixtures::PAYMENT_ID);
        $otherApi->add(200, Fixtures::PAYMENT_REFUNDED);
        $payment->refund(['api_key' => Fixtures::TEST_KEY, 'timeout' => 5]);
        $request = $otherApi->last();
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $request->header('authorization'));
        self::assertSame([5.0, 5.0], [$request->timeout, $request->connectTimeout]);
        self::assertSame([], $this->api->requests);
    }

    /**
     * Payment::simulateTransfer($id), con la clave global puesta en la clave secreta de prueba de
     * un sandbox: el de $client->testHelpers->payments, sin Idempotency-Key. Devuelve el Payment
     * o el ConnectOrder, según "object".
     */
    public function testSimulateTransferWithTheGlobalStyle(): void
    {
        RayaCash::setApiKey(Fixtures::SANDBOX_KEY);
        RayaCash::setHttpClient($this->api);
        $this->api->add(200, Fixtures::SANDBOX_PAYMENT_SUCCEEDED);
        $payment = Payment::simulateTransfer(Fixtures::SANDBOX_PAYMENT_ID);
        self::assertSame(Payment::class, $payment::class);
        self::assertSame('succeeded', $payment->status);
        $request = $this->api->last();
        self::assertSame(
            ['POST', '/v1/test/payments/' . Fixtures::SANDBOX_PAYMENT_ID . '/simulate_transfer'],
            [$request->method, $request->path],
        );
        self::assertNull($request->body);
        self::assertNull($request->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::SANDBOX_KEY, $this->lastAuthorization());

        $this->api->add(200, Fixtures::SANDBOX_ORDER_SUCCEEDED);
        $order = Payment::simulateTransfer(
            Fixtures::SANDBOX_ORDER_ID,
            ['amount' => 1500],
            ['timeout' => 5],
        );
        self::assertSame(ConnectOrder::class, $order::class);
        self::assertSame('{"amount":1500}', $this->api->last()->body);
        self::assertSame(5.0, $this->api->last()->timeout);
        self::assertThrows(
            \TypeError::class,
            static fn() => Payment::simulateTransfer(
                Fixtures::SANDBOX_PAYMENT_ID,
                [],
                ['idempotency_key' => 'k'],
            ),
            'testHelpers->payments->simulateTransfer no acepta idempotency_key',
        );
        self::assertCount(2, $this->api->requests);
    }

    /**
     * De PHP: refundById() es estático, así que aunque lo llames desde un pago
     * ($payment->refundById()) usa el estilo global, no el cliente del pago. Y Payment::refund($id)
     * no reembolsa nada: es un Error del lenguaje (refund() es del pago).
     */
    public function testRefundByIdIsStaticAndUsesTheGlobalStyle(): void
    {
        $this->globalApi();
        $otherApi = new MockApi();
        $otherApi->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $otherApi->client(Fixtures::OTHER_KEY)->payments->retrieve(Fixtures::PAYMENT_ID);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        self::assertSame('refunded', $payment->refundById($payment->id)->status);
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
        self::assertCount(1, $otherApi->requests);

        $error = self::assertThrows(
            \Error::class,
            // @phpstan-ignore method.staticCall, argument.type (como Payment.refund(id) en Python)
            static fn() => Payment::refund(Fixtures::PAYMENT_ID),
            'cannot be called statically',
        );
        self::assertSame(\Error::class, $error::class);
        self::assertCount(1, $this->api->requests);
    }

    public function testEventsFromWebhookConstructEventUseTheGlobalStyle(): void
    {
        $this->globalApi();
        $body = json_encode(Fixtures::EVENT_PAYMENT_SUCCEEDED, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
        $event = Webhook::constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
        $payment = $event->data->object;
        self::assertInstanceOf(Payment::class, $payment);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        // refund_async() en Python: acá no hay async.
        $payment->refund(['idempotency_key' => 'r-2']);
        self::assertSame('r-2', $this->api->last()->header('idempotency-key'));
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function paymentsWithoutId(): iterable
    {
        yield 'sin id' => [['object' => 'payment']];
        // De PHP, con la misma regla que Python: el id tiene que ser un string no vacío.
        yield 'id vacío' => [['object' => 'payment', 'id' => '']];
        yield 'id null' => [['object' => 'payment', 'id' => null]];
        yield 'id numérico' => [['object' => 'payment', 'id' => 1042]];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('paymentsWithoutId')]
    public function testRefundNeedsAnId(array $values): void
    {
        $this->globalApi();
        $payment = Payment::constructFrom($values);
        $error = self::assertThrows(
            \ValueError::class,
            static fn() => $payment->refund(),
            'no tiene id',
        );
        self::assertSame('Este Payment no tiene id: no se puede reembolsar.', $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function environmentSources(): iterable
    {
        yield 'getenv' => ['getenv']; // como os.environ en Python
        // De PHP: los otros dos lugares de donde se lee el entorno ($_ENV → $_SERVER → getenv).
        yield '$_ENV' => ['$_ENV'];
        yield '$_SERVER' => ['$_SERVER'];
    }

    #[DataProvider('environmentSources')]
    public function testKeyFromEnvironmentAndChangesBetweenCalls(string $source): void
    {
        RayaCash::setHttpClient($this->api);
        self::putSecretKey($source, Fixtures::OTHER_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->lastAuthorization());

        RayaCash::setApiKey(Fixtures::TEST_KEY); // gana sobre la variable de entorno
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());

        // De PHP: con null vuelve a la variable, que el estilo global lee en cada request.
        RayaCash::setApiKey(null);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $this->lastAuthorization());
        self::putSecretKey($source, Fixtures::TEST_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $this->lastAuthorization());
    }

    public function testMissingKey(): void
    {
        RayaCash::setHttpClient($this->api);
        $error = self::assertThrows(
            AuthenticationException::class,
            static fn() => Balance::retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        // De PHP: el mensaje dice cómo darla también en el estilo global.
        self::assertStringContainsString('RayaCash::setApiKey()', $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badKeys(): iterable
    {
        yield 'vacía' => ['', 'API_KEY_MISSING'];
        yield 'solo espacios' => ['   ', 'API_KEY_MISSING'];
        yield 'pública' => [
            'raya_pk_live_' . str_repeat('a', 48),
            'API_KEY_PUBLISHABLE_NOT_ALLOWED',
        ];
        yield 'con un salto de línea' => [Fixtures::TEST_KEY . "\n", 'API_KEY_INVALID'];
    }

    /**
     * De PHP: setApiKey() no valida (un .env vacío no rompe el arranque de tu framework), pero
     * cada request sí, antes de mandar nada. Una clave asignada gana sobre RAYA_SECRET_KEY aunque
     * esté vacía, como rayacash.api_key = "" en Python.
     */
    #[DataProvider('badKeys')]
    public function testTheKeyIsCheckedOnEachRequest(string $key, string $code): void
    {
        putenv('RAYA_SECRET_KEY=' . Fixtures::OTHER_KEY);
        RayaCash::setApiKey($key);
        RayaCash::setHttpClient($this->api);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => Balance::retrieve(),
            errorCode: $code,
        );
        self::assertSame([], $this->api->requests);
    }

    public function testGlobalSettings(): void
    {
        $this->globalApi();
        RayaCash::setApiBase('http://localhost:8000/');
        RayaCash::setTimeout(12);
        RayaCash::setMaxNetworkRetries(0);
        RayaCash::setAppInfo('mi-plugin', version: '2.0.0', url: 'https://plugin.example');
        $this->api->addError(500, 'INTERNAL_ERROR');
        self::assertThrows(ApiException::class, static fn() => Balance::retrieve());
        $request = $this->api->last();
        self::assertSame('http://localhost:8000/v1/balance', $request->url);
        self::assertSame([12.0, 10.0], [$request->timeout, $request->connectTimeout]);
        self::assertStringEndsWith(
            ' mi-plugin/2.0.0 (https://plugin.example)',
            $request->header('user-agent') ?? '',
        );
        self::assertCount(1, $this->api->requests);
        self::assertSame([], $this->sleeps);

        RayaCash::setAppInfo('solo');
        self::assertSame(['name' => 'solo'], RayaCash::getAppInfo());
        // Python falla recién en la request; acá setApiBase() falla al asignar y no cambia nada.
        self::assertThrows(
            \ValueError::class,
            static fn() => RayaCash::setApiBase('http://inseguro.example'),
            'api_base',
        );
        self::assertSame('http://localhost:8000', RayaCash::getApiBase());
        // Una query o un fragmento vacíos no quedan en la api_base (ClientTest::validApiBases).
        RayaCash::setApiBase('https://api.raya.cash/?#');
        self::assertSame('https://api.raya.cash', RayaCash::getApiBase());
    }

    public function testSwappingTheGlobalHttpClient(): void
    {
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        RayaCash::setHttpClient($this->api);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();

        $other = new MockApi();
        RayaCash::setHttpClient($other);
        $other->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertCount(1, $this->api->requests);
        self::assertCount(1, $other->requests);
    }

    /** Con Transport::$factory en lugar del monkeypatch de HTTPClientManager._new_sync_client. */
    public function testOwnedGlobalClients(): void
    {
        Transport::$factory = function (): HttpClientInterface {
            $this->created[] = $this->api;
            return $this->api;
        };
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        Balance::retrieve();
        self::assertCount(1, $this->created); // el estilo global reusa su cliente HTTP

        // De PHP: RayaCash::reset() lo olvida, y la request siguiente crea otro.
        RayaCash::reset();
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertCount(2, $this->created);
    }

    /**
     * «config está sellado y setAppInfo valida» (Node). RayaCash no tiene propiedades públicas
     * (todo pasa por los setters, que validan) y no se instancia ni se extiende.
     */
    public function testTheConfigIsSealedAndSetAppInfoValidates(): void
    {
        $facade = new \ReflectionClass(RayaCash::class);
        self::assertSame([], $facade->getProperties(\ReflectionProperty::IS_PUBLIC));
        self::assertFalse($facade->isInstantiable());
        self::assertTrue($facade->isFinal());

        // setAppInfo("x", "1.0") es válido acá: lo que no va es la forma de app_info de RayaClient.
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => RayaCash::setAppInfo(['name' => 'x', 'version' => '1.0']),
        );
        foreach (['', '   '] as $name) {
            self::assertThrows(
                \ValueError::class,
                static fn() => RayaCash::setAppInfo($name),
                'app_info necesita un name.',
            );
        }
        self::assertThrows(
            \ValueError::class,
            static fn() => RayaCash::setAppInfo('mi-plugin', "2.0\r\nX-Otro: 1"),
            'app_info solo admite caracteres ASCII visibles: va en el header User-Agent.',
        );
        self::assertNull(RayaCash::getAppInfo());
    }

    /**
     * «los valores inválidos de config fallan en la request» (Node): acá fallan al asignarlos (Q1
     * del diseño), en la línea del error, y no cambian nada. Un app_info con campos de más no se
     * puede escribir: setAppInfo() solo tiene name, version y url.
     *
     * @return iterable<string, array{\Closure(): void, class-string<\Throwable>, string|null}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'timeout como string' => [
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => RayaCash::setTimeout('60'),
            \TypeError::class,
            null,
        ];
        yield 'timeout en 0' => [
            static fn() => RayaCash::setTimeout(0),
            \ValueError::class,
            'timeout tiene que ser un número de segundos mayor que 0.',
        ];
        yield 'timeout NAN' => [
            static fn() => RayaCash::setTimeout(NAN),
            \ValueError::class,
            'timeout tiene que ser un número de segundos mayor que 0.',
        ];
        yield 'timeout de más de 24 días' => [
            static fn() => RayaCash::setTimeout(2147484),
            \ValueError::class,
            'timeout no puede pasar de 2147483 segundos (unos 24 días).',
        ];
        // Como en Python y Node: un bool no es un número (sin strict_types, ClientTest). La firma
        // acepta bool para rechazarlo, así que PHPStan no lo marca (como mypy en Python). El
        // mensaje no nombra el tipo, como el de Python: sin strict_types, pudo ser un texto.
        yield 'timeout bool' => [
            static fn() => RayaCash::setTimeout(true),
            \TypeError::class,
            'timeout tiene que ser un número de segundos, como 65.',
        ];
        yield 'max_network_retries negativo' => [
            static fn() => RayaCash::setMaxNetworkRetries(-1),
            \ValueError::class,
            'max_network_retries no puede ser negativo.',
        ];
        yield 'max_network_retries bool' => [
            static fn() => RayaCash::setMaxNetworkRetries(false),
            \TypeError::class,
            'max_network_retries tiene que ser un int.',
        ];
        // Lo mismo con decimales: la firma acepta float para rechazarlo (sin strict_types, PHP
        // convertiría 1.5 en 1), y PHPStan tampoco lo marca.
        yield 'max_network_retries con decimales' => [
            static fn() => RayaCash::setMaxNetworkRetries(1.5),
            \TypeError::class,
            'max_network_retries tiene que ser un int.',
        ];
        yield 'http_client que no es un cliente' => [
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => RayaCash::setHttpClient('fetch'),
            \TypeError::class,
            null,
        ];
        yield 'api_base sin https' => [
            static fn() => RayaCash::setApiBase('http://inseguro.example'),
            \ValueError::class,
            'api_base tiene que ser una URL https:// sin query ni credenciales (o '
                . 'http://localhost para desarrollo local).',
        ];
        yield 'log desconocido' => [
            // @phpstan-ignore argument.type (lo que haría alguien sin PHPStan)
            static fn() => RayaCash::setLog('verbose'),
            \ValueError::class,
            "log tiene que ser 'info', 'debug' o null.",
        ];
    }

    /**
     * @param \Closure(): void $set
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidConfigValuesFailWhenSet(
        \Closure $set,
        string $class,
        ?string $message,
    ): void {
        $error = self::assertThrows($class, $set);
        if ($message !== null) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame(self::FACTORY, self::settings());
        self::assertSame([], $this->api->requests);
    }

    /**
     * De PHP: los argumentos de más en los estáticos y en $payment->refund() fallan antes de
     * mandar nada (PHP los descartaría en silencio, y con ellos la clave de otra cuenta).
     *
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function extraArguments(): iterable
    {
        $options = ['api_key' => Fixtures::OTHER_KEY];
        $params = ['amount' => 1, 'destination' => 'x'];
        $key = ['idempotency_key' => 'k'];
        yield 'Balance::retrieve' => [
            static fn(): mixed => Balance::retrieve([], $options),
            'Balance::retrieve recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield 'Transfer::create' => [
            static fn(): mixed => Transfer::create($params, $key, $options),
            'Transfer::create recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'Transfer::retrieve' => [
            static fn(): mixed => Transfer::retrieve('t', [], $options),
            'Transfer::retrieve recibe 2 argumentos (transferId y options), no 3: las opciones van '
                . "en el segundo, como ['api_key' => …].",
        ];
        yield 'Transfer::list' => [
            static fn(): mixed => Transfer::list([], [], $options),
            'Transfer::list recibe 2 argumentos (params y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'Payment::create' => [
            static fn(): mixed => Payment::create($params, [], $options),
            'Payment::create recibe 2 argumentos (params y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'Payment::retrieve' => [
            static fn(): mixed => Payment::retrieve('p', [], $options),
            'Payment::retrieve recibe 2 argumentos (paymentId y options), no 3: las opciones van '
                . "en el segundo, como ['api_key' => …].",
        ];
        yield 'Payment::refundById' => [
            static fn(): mixed => Payment::refundById('p', [], $options),
            'Payment::refundById recibe 2 argumentos (paymentId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield '$payment->refund' => [
            static fn(): mixed => Payment::constructFrom(Fixtures::PAYMENT_SUCCEEDED)
                ->refund([], $options),
            '$payment->refund recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield 'Payment::simulateTransfer' => [
            static fn(): mixed => Payment::simulateTransfer('p', [], [], $options),
            'Payment::simulateTransfer recibe 3 argumentos (paymentId, params y options), no 4: '
                . "las opciones van en el tercero, como ['api_key' => …].",
        ];
        yield 'OAuth::token' => [
            static fn(): mixed => OAuth::token(self::TOKEN_PARAMS, [], $options),
            'OAuth::token recibe 2 argumentos (params y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'User::retrieve' => [
            static fn(): mixed => User::retrieve('u', [], $options),
            'User::retrieve recibe 2 argumentos (userId y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'ConnectOrder::create' => [
            static fn(): mixed => ConnectOrder::create(['items' => [self::ITEM]], [], $options),
            'ConnectOrder::create recibe 2 argumentos (params y options), no 3: las opciones van '
                . "en el segundo, como ['api_key' => …].",
        ];
        yield 'ConnectOrder::retrieve' => [
            static fn(): mixed => ConnectOrder::retrieve('c', [], $options),
            'ConnectOrder::retrieve recibe 2 argumentos (orderId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'ConnectOrder::refundById' => [
            static fn(): mixed => ConnectOrder::refundById('c', [], $options),
            'ConnectOrder::refundById recibe 2 argumentos (orderId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield '$connectOrder->refund' => [
            static fn(): mixed => ConnectOrder::constructFrom(Fixtures::CONNECT_ORDER_SUCCEEDED)
                ->refund([], $options),
            '$connectOrder->refund recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield 'IdentityVerification::create' => [
            static fn(): mixed => IdentityVerification::create(
                ['type' => 'document'],
                [],
                $options,
            ),
            'IdentityVerification::create recibe 2 argumentos (params y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
        yield 'IdentityVerification::retrieve' => [
            static fn(): mixed => IdentityVerification::retrieve('idv_x', [], $options),
            'IdentityVerification::retrieve recibe 2 argumentos (verificationId y options), no 3: '
                . "las opciones van en el segundo, como ['api_key' => …].",
        ];
        yield 'IdentityVerification::list' => [
            static fn(): mixed => IdentityVerification::list([], [], $options),
            'IdentityVerification::list recibe 2 argumentos (params y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'IdentityVerification::cancelById' => [
            static fn(): mixed => IdentityVerification::cancelById('idv_x', [], $options),
            'IdentityVerification::cancelById recibe 2 argumentos (verificationId y options), no '
                . "3: las opciones van en el segundo, como ['api_key' => …].",
        ];
        yield 'IdentityVerification::files' => [
            static fn(): mixed => IdentityVerification::files('idv_x', [], $options),
            'IdentityVerification::files recibe 2 argumentos (verificationId y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
        yield '$verification->cancel' => [
            static fn(): mixed => IdentityVerification::constructFrom(
                Fixtures::VERIFICATION_PENDING,
            )->cancel([], $options),
            '$verification->cancel recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        $person = ['identity_verification' => Fixtures::VERIFICATION_ID];
        yield 'PspCustomer::create' => [
            static fn(): mixed => PspCustomer::create($person, [], $options),
            'PspCustomer::create recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::retrieve' => [
            static fn(): mixed => PspCustomer::retrieve('pspc_x', [], $options),
            'PspCustomer::retrieve recibe 2 argumentos (customerId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::list' => [
            static fn(): mixed => PspCustomer::list([], [], $options),
            'PspCustomer::list recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::retrieveBalance' => [
            static fn(): mixed => PspCustomer::retrieveBalance('pspc_x', [], $options),
            'PspCustomer::retrieveBalance recibe 2 argumentos (customerId y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::blockById' => [
            static fn(): mixed => PspCustomer::blockById('pspc_x', [], $options),
            'PspCustomer::blockById recibe 2 argumentos (customerId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::unblockById' => [
            static fn(): mixed => PspCustomer::unblockById('pspc_x', [], $options),
            'PspCustomer::unblockById recibe 2 argumentos (customerId y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::closeById' => [
            static fn(): mixed => PspCustomer::closeById('pspc_x', [], $options),
            'PspCustomer::closeById recibe 2 argumentos (customerId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'PspCustomer::recreateById' => [
            static fn(): mixed => PspCustomer::recreateById('pspc_x', [], $options),
            'PspCustomer::recreateById recibe 2 argumentos (customerId y options), no 3: las '
                . "opciones van en el segundo, como ['api_key' => …].",
        ];
        yield '$pspCustomer->block' => [
            static fn(): mixed => PspCustomer::constructFrom(Fixtures::PSP_CUSTOMER_ACTIVE)
                ->block([], $options),
            '$pspCustomer->block recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield '$pspCustomer->unblock' => [
            static fn(): mixed => PspCustomer::constructFrom(Fixtures::PSP_CUSTOMER_BLOCKED)
                ->unblock([], $options),
            '$pspCustomer->unblock recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield '$pspCustomer->close' => [
            static fn(): mixed => PspCustomer::constructFrom(Fixtures::PSP_CUSTOMER_ACTIVE)
                ->close([], $options),
            '$pspCustomer->close recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield '$pspCustomer->recreate' => [
            static fn(): mixed => PspCustomer::constructFrom(Fixtures::PSP_CUSTOMER_CLOSED)
                ->recreate([], $options),
            '$pspCustomer->recreate recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield 'PspTransfer::create' => [
            static fn(): mixed => PspTransfer::create(
                ['customer' => 'pspc_x', 'amount' => 1, 'destination' => 'x'],
                $key,
                $options,
            ),
            'PspTransfer::create recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'PspTransfer::retrieve' => [
            static fn(): mixed => PspTransfer::retrieve('pspt_x', [], $options),
            'PspTransfer::retrieve recibe 2 argumentos (transferId y options), no 3: las opciones '
                . "van en el segundo, como ['api_key' => …].",
        ];
        yield 'PspTransfer::list' => [
            static fn(): mixed => PspTransfer::list([], [], $options),
            'PspTransfer::list recibe 2 argumentos (params y options), no 3: las opciones van en '
                . "el segundo, como ['api_key' => …].",
        ];
        yield 'PspBalance::retrieve' => [
            static fn(): mixed => PspBalance::retrieve([], $options),
            'PspBalance::retrieve recibe 1 argumento (options), no 2: las opciones van en el '
                . "primero, como ['api_key' => …].",
        ];
        yield 'Invoice::create' => [
            static fn(): mixed => Invoice::create(
                ['dni' => Fixtures::INVOICE_DNI, 'amount' => 15000],
                $key,
                $options,
            ),
            'Invoice::create recibe 2 argumentos (params y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
        yield 'Invoice::retrieve' => [
            static fn(): mixed => Invoice::retrieve('inv_x', [], $options),
            'Invoice::retrieve recibe 2 argumentos (invoiceId y options), no 3: las opciones van '
                . "en el segundo, como ['api_key' => …].",
        ];
        yield 'Invoice::list' => [
            static fn(): mixed => Invoice::list([], [], $options),
            'Invoice::list recibe 2 argumentos (params y options), no 3: las opciones van en el '
                . "segundo, como ['api_key' => …].",
        ];
    }

    /** @param \Closure(): mixed $call */
    #[DataProvider('extraArguments')]
    public function testExtraArgumentsFailBeforeSending(\Closure $call, string $message): void
    {
        $this->globalApi();
        $error = self::assertThrows(\ArgumentCountError::class, $call);
        self::assertSame($message, $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /** De PHP: un RayaClient tiene su propia configuración y nunca lee la del estilo global. */
    public function testAClientNeverReadsTheGlobalSettings(): void
    {
        $globalApi = new MockApi();
        RayaCash::setApiKey(Fixtures::OTHER_KEY);
        RayaCash::setApiBase('https://otra.example');
        RayaCash::setTimeout(5);
        RayaCash::setMaxNetworkRetries(0);
        RayaCash::setHttpClient($globalApi);
        RayaCash::setAppInfo('mi-plugin', '2.0.0');
        $this->api->addError(500, 'INTERNAL_ERROR');
        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client()->balance->retrieve();
        $request = $this->api->last();
        self::assertSame('https://api.raya.cash/v1/balance', $request->url);
        self::assertSame('Bearer ' . Fixtures::TEST_KEY, $request->header('authorization'));
        self::assertSame([65.0, 10.0], [$request->timeout, $request->connectTimeout]);
        self::assertStringNotContainsString('mi-plugin', $request->header('user-agent') ?? '');
        self::assertCount(2, $this->api->requests); // reintentó: 2 por defecto, no los 0 globales
        self::assertSame([0.5], $this->sleeps);

        // Ni la clave: sin api_key ni RAYA_SECRET_KEY, un cliente no usa la de setApiKey().
        self::assertThrows(
            AuthenticationException::class,
            fn() => $this->api->client(null)->balance->retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        self::assertCount(2, $this->api->requests);
        self::assertSame([], $globalApi->requests);
    }

    /** De PHP: RayaCash::reset() (para tus tests del estilo global) vuelve todo a fábrica. */
    public function testResetGoesBackToTheFactoryValues(): void
    {
        $logger = new class implements LoggerInterface {
            /** @param array<string, mixed> $context */
            public function info(string $message, array $context = []): void {}

            /** @param array<string, mixed> $context */
            public function debug(string $message, array $context = []): void {}
        };
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        RayaCash::setApiBase('http://localhost:8000');
        RayaCash::setTimeout(12.5);
        RayaCash::setMaxNetworkRetries(0);
        RayaCash::setHttpClient($this->api);
        RayaCash::setAppInfo('mi-plugin', '2.0.0');
        RayaCash::setLog('debug');
        RayaCash::setLogger($logger);
        self::assertSame(
            [
                'api_base' => 'http://localhost:8000',
                'timeout' => 12.5,
                'max_network_retries' => 0,
                'http_client' => $this->api,
                'app_info' => ['name' => 'mi-plugin', 'version' => '2.0.0'],
                'log' => 'debug',
                'logger' => $logger,
            ],
            self::settings(),
        );

        RayaCash::reset();
        self::assertSame(self::FACTORY, self::settings());
        // La clave también: sin RAYA_SECRET_KEY, la request siguiente no tiene ninguna.
        RayaCash::setHttpClient($this->api);
        self::assertThrows(
            AuthenticationException::class,
            static fn() => Balance::retrieve(),
            errorCode: 'API_KEY_MISSING',
        );
        self::assertSame([], $this->api->requests);
    }

    /** De PHP: RayaCash::reset() también olvida el RAYA_LOG que ya leyó, y lo vuelve a leer. */
    public function testResetReadsRayaLogAgain(): void
    {
        putenv('RAYA_LOG=info');
        $this->globalApi();
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertSame([], $this->logLines); // Support\TestCase la dejó leída como ausente

        RayaCash::reset();
        $this->globalApi();
        $this->api->add(200, Fixtures::BALANCE);
        Balance::retrieve();
        self::assertStringContainsString(
            'message="Request a la API de Raya" method=GET path=/v1/balance attempt=1',
            $this->logOutput(),
        );
    }

    /** Como el fixture global_api de Python: la clave y el cliente HTTP globales, a $this->api. */
    private function globalApi(): MockApi
    {
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        RayaCash::setHttpClient($this->api);
        return $this->api;
    }

    private function lastAuthorization(): ?string
    {
        return $this->api->last()->header('authorization');
    }

    /** RAYA_SECRET_KEY en uno de los tres lugares del entorno (Support\TestCase la borra). */
    private static function putSecretKey(string $source, string $key): void
    {
        if ($source === '$_ENV') {
            $_ENV['RAYA_SECRET_KEY'] = $key;
        } elseif ($source === '$_SERVER') {
            $_SERVER['RAYA_SECRET_KEY'] = $key;
        } else {
            putenv("RAYA_SECRET_KEY={$key}");
        }
    }

    /** @return array<string, mixed> La configuración global, leída con sus getters. */
    private static function settings(): array
    {
        return [
            'api_base' => RayaCash::getApiBase(),
            'timeout' => RayaCash::getTimeout(),
            'max_network_retries' => RayaCash::getMaxNetworkRetries(),
            'http_client' => RayaCash::getHttpClient(),
            'app_info' => RayaCash::getAppInfo(),
            'log' => RayaCash::getLog(),
            'logger' => RayaCash::getLogger(),
        ];
    }
}
