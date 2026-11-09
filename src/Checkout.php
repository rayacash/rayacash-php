<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\CheckoutOrderService;

/**
 * Un cobro que tu aplicación crea a nombre de una cuenta conectada, con tu comisión fija (fee).
 *
 * Quien paga transfiere a un CVU exclusivo (bank_transfer) o paga en checkout_url, nuestra página
 * con el nombre y la imagen del comercio conectado. Al acreditarse, net_amount se liquida a la
 * cuenta conectada y fee a la tuya; te avisamos con checkout.succeeded. Entregá solo cuando status
 * es succeeded. El id es el del pago: la cuenta conectada lo ve con su propia clave en
 * `$client->payments->retrieve()`. status puede sumar valores: tu código tiene que ignorarlos.
 *
 * @property-read string $id El mismo id del pago.
 * @property-read 'checkout' $object
 * @property-read 'pending'|'succeeded'|'failed'|'expired'|'refunded' $status
 * @property-read int|float $amount Cuánto tiene que transferir quien paga, en pesos: la suma de
 *     los ítems.
 * @property-read int|float $fee Tu comisión fija, en pesos: se liquida a la cuenta dueña de la app.
 * @property-read int|float $net_amount Lo que se liquida a la cuenta conectada: lo recibido menos
 *     fee.
 * @property-read int|float|null $amount_received Cuánto recibimos (null hasta que llega).
 * @property-read string $currency Siempre 'ARS'.
 * @property-read list<Checkout\Item> $items
 * @property-read string|null $description
 * @property-read string|null $external_reference Tu identificador (por ejemplo, el pedido).
 * @property-read string $account El user_id de la cuenta conectada a cuyo nombre se cobra.
 * @property-read Checkout\BankTransfer $bank_transfer
 * @property-read string $checkout_url Nuestra página de pago, con el nombre y la imagen del
 *     comercio conectado.
 * @property-read Checkout\BackUrls $back_urls A dónde vuelve quien paga desde checkout_url.
 * @property-read 'approved'|null $auto_return approved: al acreditarse, quien paga vuelve solo a
 *     back_urls->success.
 * @property-read string|null $notification_url El destino de Developers › Webhooks (we_…) que
 *     recibe los eventos de este checkout; null si van a los suscriptos a checkout.*.
 * @property-read Checkout\Payer|null $payer
 * @property-read 'amount_mismatch'|null $failure_code amount_mismatch si transfirieron de menos.
 * @property-read array<array-key, string> $metadata Tus pares clave-valor ([] si no mandaste).
 *     PHP convierte las claves que parecen enteros en int, y "0", "1"… en una lista: para
 *     mandarla de vuelta, (object) $checkout->metadata.
 * @property-read string $expires_at Hasta cuándo se puede pagar (ISO 8601 en UTC).
 * @property-read string|null $paid_at
 * @property-read string|null $refunded_at
 * @property-read string $created_at
 */
final class Checkout extends RayaObject
{
    public const OBJECT_NAME = 'checkout';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    public const FAILURE_CODE_AMOUNT_MISMATCH = 'amount_mismatch';

    /** El único auto_return que existe: quien paga vuelve solo a back_urls->success. */
    public const AUTO_RETURN_APPROVED = 'approved';

    protected const INNER_CLASSES = [
        'items' => Checkout\Item::class,
        'bank_transfer' => Checkout\BankTransfer::class,
        'back_urls' => Checkout\BackUrls::class,
        'payer' => Checkout\Payer::class,
    ];
    protected const PLAIN_FIELDS = ['metadata'];

    /**
     * Reembolsa este checkout completo, con el cliente del que vino (o con el estilo global, si lo
     * armaste a mano o lo deserializaste). Devuelve el checkout actualizado: este objeto no
     * cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function refund(#[\SensitiveParameter] array $options = []): Checkout
    {
        Params::arguments('$checkout->refund', func_num_args(), ['options']);
        $id = $this->rawValue('id');
        if (!is_string($id) || $id === '') {
            throw new \ValueError('Este Checkout no tiene id: no se puede reembolsar.');
        }
        return (new CheckoutOrderService($this->resolveRequestor()))->refund($id, $options);
    }

    // --- Estilo global (Checkout::create/retrieve/refundById); ver CheckoutOrderService. ---

    /**
     * Crea un checkout a nombre de la cuenta conectada cuyo access_token está en
     * RayaCash::setApiKey() (o en api_key). Usá un timeout de al menos 60 s (es el default).
     *
     * @param array{
     *     items: list<array{
     *         title: string,
     *         quantity: int,
     *         unit_price: int|float|string|\Stringable,
     *     }>,
     *     fee?: int|float|string|\Stringable|null,
     *     description?: string|null,
     *     external_reference?: string|null,
     *     metadata?: array<string, string>|\stdClass|null,
     *     back_urls?: array{success?: string|null, failure?: string|null}|null,
     *     auto_return?: 'approved'|null,
     *     notification_url?: string|null,
     *     expires_in?: int|null,
     * } $params
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function create(
        array $params,
        #[\SensitiveParameter]
        array $options = [],
    ): Checkout {
        Params::arguments('Checkout::create', func_num_args(), ['params', 'options']);
        return (new CheckoutOrderService(GlobalState::requestor()))->create($params, $options);
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $checkoutId,
        #[\SensitiveParameter]
        array $options = [],
    ): Checkout {
        Params::arguments('Checkout::retrieve', func_num_args(), ['checkoutId', 'options']);
        return (new CheckoutOrderService(GlobalState::requestor()))
            ->retrieve($checkoutId, $options);
    }

    /**
     * Reembolsa un checkout por id, con el estilo global. Desde un checkout que ya tenés, usá
     * `$checkout->refund()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function refundById(
        string $checkoutId,
        #[\SensitiveParameter]
        array $options = [],
    ): Checkout {
        Params::arguments('Checkout::refundById', func_num_args(), ['checkoutId', 'options']);
        return (new CheckoutOrderService(GlobalState::requestor()))->refund($checkoutId, $options);
    }
}
