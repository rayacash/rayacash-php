<?php

declare(strict_types=1);

// Los tipos de la API pública, como los ve PHPStan en el código de quien integra. Port de
// tests/typecheck/check_types.py (mypy y pyright) y de test/types.test-d.ts (tsc): si un tipo de
// retorno cambia sin querer, falla su assertType(). Lo lee tests/Types/TypesTest.php y nunca se
// ejecuta; lo que PHPStan tiene que rechazar está en expect-errors.php.
//
// Los nombres de las funciones son los de Python. Las de async (async_style y
// global_async_style) no tienen par: PHP es sincrónico.

namespace RayaCash\Tests\Types\Data;

use RayaCash\Balance;
use RayaCash\Checkout;
use RayaCash\Event;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\HttpClient\Request;
use RayaCash\HttpClient\Response;
use RayaCash\OAuth;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\RayaClient;
use RayaCash\RayaObject;
use RayaCash\Transfer;
use RayaCash\User;
use RayaCash\Util\Uuid;
use RayaCash\Webhook;
use RayaCash\WebhookSignature;

use function PHPStan\Testing\assertType;

/** $decimal: un decimal exacto (BcMath\Number, BigDecimal), como el Decimal de Python. */
function clientStyle(RayaClient $client, \Stringable $decimal): void
{
    assertType('RayaCash\Balance', $client->balance->retrieve());
    // Python: float. Los montos son los del JSON: int o float.
    assertType('float|int', $client->balance->retrieve()->available);
    // Node: lastResponse.
    assertType('RayaCash\RayaResponse|null', $client->balance->retrieve()->getLastResponse());

    $transfer = $client->transfers->create(
        ['amount' => $decimal, 'destination' => '0000123380736153041182'],
        ['idempotency_key' => 'k'],
    );
    assertType('RayaCash\Transfer', $transfer);
    assertType('RayaCash\Transfer\Counterparty', $transfer->counterparty);
    assertType('string|null', $transfer->counterparty->tax_id);
    // Node: la unión de los status (pueden sumarse valores).
    assertType("'completed'|'failed'|'pending'|'reversed'", $transfer->status);
    assertType('RayaCash\Transfer', $client->transfers->retrieve('id'));

    // Node suma created_gte con una fecha.
    $page = $client->transfers->list([
        'direction' => 'inbound',
        'limit' => 100,
        'created_gte' => new \DateTimeImmutable(),
    ]);
    assertType('RayaCash\ListObject<RayaCash\Transfer>', $page);
    assertType('list<RayaCash\Transfer>', $page->data);
    assertType('string|null', $page->next_cursor);
    foreach ($page as $item) {
        assertType('RayaCash\Transfer', $item);
    }
    foreach ($page->autoPagingIter() as $item) {
        assertType('RayaCash\Transfer', $item);
    }
    // Python: next_page_async(), en async_style.
    assertType('RayaCash\ListObject<RayaCash\Transfer>', $page->nextPage());

    $payment = $client->payments->create([
        'amount' => 15000,
        'metadata' => ['cliente_id' => '8812'],
    ]);
    assertType('RayaCash\Payment', $payment);
    assertType('string|null', $payment->bank_transfer->alias);
    assertType('RayaCash\Payment\Payer|null', $payment->payer);
    // Claves int o string (array<string> es array<array-key, string>): PHP convierte las que
    // parecen enteros ('8812') en int. Se manda de vuelta como objeto: con claves "0", "1"…
    // sería una lista, y create no acepta listas.
    assertType('array<string>', $payment->metadata);
    $client->payments->create(['amount' => 1, 'metadata' => (object) $payment->metadata]);
    assertType('RayaCash\Payment', $client->payments->retrieve('id'));
    assertType('RayaCash\Payment', $client->payments->refund('id', ['idempotency_key' => 'r']));
    // Node: payment.refund().
    assertType('RayaCash\Payment', $payment->refund());
    assertType('RayaCash\RayaObject', $client->rawRequest('get', '/v1/balance'));

    $event = $client->constructEvent('{}', 't=1,v1=x', 'whsec_x');
    assertType('RayaCash\Event', $event);
    // Python: Union[Transfer, Payment, RayaObject]. PHPStan la reduce a RayaObject (Transfer y
    // Payment son subclases): el tipo concreto sale con instanceof.
    assertType('RayaCash\RayaObject', $event->data->object);
    $object = $event->data->object;
    if ($object instanceof Payment) {
        assertType('RayaCash\Payment', $object);
    } elseif ($object instanceof Transfer) {
        assertType('RayaCash\Transfer', $object);
    } elseif ($object instanceof Checkout) {
        assertType('RayaCash\Checkout', $object);
    }
}

/** Apps y cuentas conectadas: el canje del code, la cuenta y sus checkouts. */
function appsStyle(RayaClient $client, \Stringable $decimal): void
{
    $token = $client->oauth->token([
        'client_id' => '7650337728110670',
        'client_secret' => 'raya_cs_live_x',
        'code' => 'c0d3',
        'redirect_uri' => 'https://tienda.example.com/raya/callback',
    ]);
    assertType('RayaCash\OAuthToken', $token);
    assertType('string', $token->access_token);
    assertType('string', $token->user_id);
    assertType('int|null', $token->expires_in);
    assertType('string|null', $token->refresh_token);

    $connected = new RayaClient($token->access_token);
    $user = $connected->users->retrieve($token->user_id);
    assertType('RayaCash\User', $user);
    assertType("'business'|'personal'", $user->type);
    assertType("'personal'", User::TYPE_PERSONAL);
    assertType('RayaCash\User\Identification|null', $user->identification);
    assertType('string|null', $user->identification?->number);
    assertType("'F'|'M'|'X'|null", $user->sex);
    assertType('RayaCash\User\Address|null', $user->address);
    assertType('string|null', $user->address?->city);
    assertType('bool', $user->identity_verified);
    assertType('RayaCash\User\Business|null', $user->business);
    assertType('RayaCash\User\Representative|null', $user->business?->representative);
    assertType('RayaCash\User\Merchant|null', $user->merchant);
    assertType('string|null', $user->merchant?->image);

    $checkout = $connected->checkout->orders->create([
        'items' => [['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => $decimal]],
        'fee' => '500.00',
        'back_urls' => ['success' => 'https://tienda.example.com/pedidos/1042'],
        'auto_return' => 'approved',
        'notification_url' => 'we_x',
        'expires_in' => 3600,
        'metadata' => ['pedido_id' => '1042'],
    ]);
    assertType('RayaCash\Checkout', $checkout);
    assertType("'expired'|'failed'|'pending'|'refunded'|'succeeded'", $checkout->status);
    assertType("'succeeded'", Checkout::STATUS_SUCCEEDED);
    assertType('float|int', $checkout->fee);
    assertType('float|int', $checkout->net_amount);
    assertType('float|int|null', $checkout->amount_received);
    assertType('list<RayaCash\Checkout\Item>', $checkout->items);
    assertType('string', $checkout->account);
    assertType('RayaCash\Checkout\BankTransfer', $checkout->bank_transfer);
    assertType('RayaCash\Checkout\BackUrls', $checkout->back_urls);
    assertType('string|null', $checkout->back_urls->success);
    assertType("'approved'|null", $checkout->auto_return);
    assertType("'approved'", Checkout::AUTO_RETURN_APPROVED);
    assertType('string|null', $checkout->notification_url);
    assertType('RayaCash\Checkout\Payer|null', $checkout->payer);
    assertType('array<string>', $checkout->metadata);
    assertType('RayaCash\Checkout', $connected->checkout->orders->retrieve('id'));
    assertType(
        'RayaCash\Checkout',
        $connected->checkout->orders->refund('id', ['idempotency_key' => 'r']),
    );
    assertType('RayaCash\Checkout', $checkout->refund());
    foreach ($checkout->items as $item) {
        assertType('RayaCash\Checkout\Item', $item);
        assertType('int', $item->quantity);
        assertType('float|int', $item->unit_price);
    }
}

function globalStyle(): void
{
    // Node: config.apiKey = process.env.RAYA_SECRET_KEY; config.maxNetworkRetries = 4.
    RayaCash::setApiKey(getenv('RAYA_SECRET_KEY') ?: null);
    RayaCash::setMaxNetworkRetries(4);
    assertType('RayaCash\Balance', Balance::retrieve());
    $transfer = Transfer::create(
        ['amount' => 1, 'destination' => 'alias.prueba'],
        ['idempotency_key' => 'k'],
    );
    assertType('RayaCash\Transfer', $transfer);
    assertType('RayaCash\ListObject<RayaCash\Transfer>', Transfer::list());
    $payment = Payment::retrieve('id');
    assertType('RayaCash\Payment', $payment->refund());
    // Python: Payment.refund("id"). PHP no deja tener un refund estático y otro de instancia.
    assertType('RayaCash\Payment', Payment::refundById('id'));
    // Node.
    assertType('RayaCash\Payment', Payment::constructFrom(['object' => 'payment']));
    // Apps y cuentas conectadas: OAuth::token no necesita la clave global.
    assertType(
        'RayaCash\OAuthToken',
        OAuth::token([
            'client_id' => '7650337728110670',
            'client_secret' => 'raya_cs_live_x',
            'code' => 'c0d3',
            'redirect_uri' => 'https://tienda.example.com/raya/callback',
        ]),
    );
    assertType('RayaCash\User', User::retrieve('id'));
    $checkout = Checkout::create([
        'items' => [['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000]],
    ]);
    assertType('RayaCash\Checkout', $checkout);
    assertType('RayaCash\Checkout', $checkout->refund());
    assertType('RayaCash\Checkout', Checkout::retrieve('id'));
    assertType('RayaCash\Checkout', Checkout::refundById('id'));
}

function errors(
    RayaException $error,
    ApiException $apiError,
    InvalidRequestException $invalid,
): void {
    // Python: error.code y error.request_id.
    assertType('string|null', $error->getErrorCode());
    assertType('string|null', $error->getRequestId());
    if ($error instanceof InvalidRequestException) {
        assertType('string|null', $error->getParam());
    }
    // Node: retryAfter, apiError.headers e invalid.param.
    assertType('float|null', $error->getRetryAfter());
    assertType('RayaCash\HttpClient\Headers', $apiError->getHttpHeaders());
    assertType('string|null', $invalid->getParam());
}

// --- Lo que agrega PHP ---------------------------------------------------------------------------

/** Las formas del constructor y las opciones por llamada: todas pasan PHPStan. */
function clientShapes(string $key, HttpClientInterface $http): void
{
    assertType('RayaCash\RayaClient', new RayaClient());
    assertType('RayaCash\RayaClient', new RayaClient($key, ['timeout' => 90]));
    assertType(
        'RayaCash\RayaClient',
        new RayaClient(['api_key' => $key, 'max_network_retries' => 4, 'http_client' => $http]),
    );
    assertType('RayaCash\RayaClient', new RayaClient(config: ['timeout' => 90.5]));
    assertType('RayaCash\RayaClient', new RayaClient(config: ['api_key' => $key]));
    $appInfo = ['name' => 'mi-plugin', 'version' => '1.2.0', 'url' => 'https://plugin.example'];
    $client = new RayaClient($key, ['app_info' => $appInfo]);
    $options = ['timeout' => 10, 'max_network_retries' => 0, 'api_key' => $key];
    assertType('RayaCash\Balance', $client->balance->retrieve($options));
    $object = $client->rawRequest(
        'post',
        '/v1/algo',
        body: ['a' => 1],
        options: ['idempotency_key' => 'k'],
    );
    assertType('RayaCash\RayaObject', $object);
}

/** Los campos: uniones de literales, constantes y lo que no está en la doc de Python. */
function objects(Payment $payment, Transfer $transfer, Event $event, Balance $balance): void
{
    assertType("'inbound'|'outbound'", $transfer->direction);
    assertType("'inbound'", Transfer::DIRECTION_INBOUND);
    assertType("'expired'|'failed'|'pending'|'refunded'|'succeeded'", $payment->status);
    assertType("'succeeded'", Payment::STATUS_SUCCEEDED);
    assertType("'amount_mismatch'|null", $payment->failure_code);
    assertType('float|int|null', $payment->amount_received);
    assertType('string|null', $payment->paid_at);
    assertType(
        "'checkout.created'|'checkout.failed'|'checkout.refunded'|'checkout.succeeded'"
        . "|'payment.created'|'payment.failed'|'payment.refunded'|'payment.succeeded'"
        . "|'transfer.failed'|'transfer.received'|'transfer.sent'|'webhook.test'",
        $event->type,
    );
    assertType("'payment.succeeded'", Event::TYPE_PAYMENT_SUCCEEDED);
    assertType("'checkout.succeeded'", Event::TYPE_CHECKOUT_SUCCEEDED);
    assertType('string', $balance->currency);
    // Lo que no tiene tipo en la doc, por nombre: mixed (un campo nuevo de la API, por ejemplo).
    assertType('mixed', $payment['campo_nuevo']);
    assertType('array<mixed>', $payment->toArray());
    assertType('stdClass', $payment->jsonSerialize());
    assertType('string|null', $payment->getLastResponse()?->requestId);

    $response = $payment->getLastResponse();
    if ($response !== null) {
        assertType('int', $response->httpStatus);
        assertType('string|null', $response->idempotencyKey);
        assertType('string', $response->body);
        assertType('stdClass', $response->data);
        assertType('string|null', $response->headers->get('Retry-After'));
    }
}

/** Las listas: count(), los campos de la página y las claves de autoPagingIter(). */
function lists(RayaClient $client): void
{
    $page = $client->transfers->list();
    assertType('bool', $page->has_more);
    assertType("'list'", $page->object);
    assertType('int<0, max>', count($page));
    foreach ($page->autoPagingIter() as $index => $item) {
        assertType('int', $index);
    }
    assertType('array<int, RayaCash\Transfer>', iterator_to_array($page->autoPagingIter()));
}

/** Los webhooks y la clave de idempotencia. */
function webhooks(RayaClient $client, string $body, ?string $header, ?string $secret): void
{
    assertType('RayaCash\Event', Webhook::constructEvent($body, $header, $secret));
    assertType('RayaCash\Event', $client->constructEvent($body, $header, $secret, tolerance: null));
    assertType('true', WebhookSignature::verifyHeader($body, $header, $secret));
    assertType('string', WebhookSignature::generateHeader($body, $secret, timestamp: 1700000000));
    assertType('string', WebhookSignature::computeSignature($body, $secret, '1700000000'));
    assertType('string', Uuid::v4());
}

/** Todos los getters de los errores, más los propios de cada clase. */
function errorGetters(RayaException $error): void
{
    assertType('int', $error->getCode());
    assertType('int|null', $error->getHttpStatus());
    assertType('array<mixed>|null', $error->getData());
    assertType('string|null', $error->getHttpBody());
    assertType('mixed', $error->getJsonBody());
    assertType('string|null', $error->getIdempotencyKey());
    assertType('string', $error->getUserMessage());
    assertType(
        'array{error_code: string|null, request_id: string|null, http_status: int|null}',
        $error->context(),
    );
    if ($error instanceof ApiConnectionException) {
        assertType('bool', $error->shouldRetry());
    }
    if ($error instanceof SignatureVerificationException) {
        assertType('string|null', $error->getSigHeader());
    }
}

/** Un cliente HTTP falso para los tests de quien integra (el bloque del README). */
function fakeHttpClient(Request $request): Response
{
    assertType('string', $request->method);
    assertType('string', $request->url);
    assertType('string|null', $request->body);
    assertType('float', $request->timeout);
    assertType('array<string, string>', $request->headers());
    assertType('string|null', $request->header('Idempotency-Key'));
    $response = new Response(200, ['Content-Type' => 'application/json'], '{"object":"balance"}');
    assertType('RayaCash\HttpClient\Headers', $response->headers);
    return $response;
}

/** La configuración global: los getters (sin getApiKey). */
function globalGetters(): void
{
    assertType('float|int', RayaCash::getTimeout());
    assertType('int', RayaCash::getMaxNetworkRetries());
    assertType('string', RayaCash::getApiBase());
    assertType("'debug'|'info'|null", RayaCash::getLog());
    assertType(
        'array{name: string, version?: string, url?: string}|null',
        RayaCash::getAppInfo(),
    );
}

/** RayaObject a mano: constructFrom devuelve la clase desde la que se llama. */
function constructFrom(): void
{
    assertType('RayaCash\RayaObject', RayaObject::constructFrom(['a' => 1]));
    assertType('RayaCash\Transfer', Transfer::constructFrom(new \stdClass()));
}
