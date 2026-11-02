<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\PaymentService;

/**
 * Un cobro único por transferencia bancaria, con un CVU y un alias exclusivos.
 *
 * Entregá el pedido solo cuando status es succeeded. Mientras está pending, el CVU y el alias
 * son exclusivos de este pago; cuando deja de estarlo, dejá de mostrarlos. status puede sumar
 * valores nuevos: tu código tiene que ignorarlos.
 *
 * @property-read string $id
 * @property-read 'payment' $object
 * @property-read 'pending'|'succeeded'|'failed'|'expired'|'refunded' $status
 * @property-read int|float $amount Cuánto tiene que transferir quien paga, en pesos.
 * @property-read int|float|null $amount_received Cuánto recibimos (null hasta que llega).
 * @property-read string $currency Siempre 'ARS'.
 * @property-read string|null $description
 * @property-read string|null $external_reference Tu identificador (por ejemplo, el pedido).
 * @property-read Payment\BankTransfer $bank_transfer
 * @property-read string $checkout_url Nuestra página de pago, que se actualiza sola.
 * @property-read string|null $return_url
 * @property-read Payment\Payer|null $payer
 * @property-read 'amount_mismatch'|null $failure_code amount_mismatch si transfirieron de menos.
 * @property-read array<array-key, string> $metadata Tus pares clave-valor ([] si no mandaste).
 *     PHP convierte las claves que parecen enteros ('8812') en int, y "0", "1"… en una lista:
 *     para mandarla de vuelta, (object) $payment->metadata.
 * @property-read string $expires_at Hasta cuándo se puede pagar (ISO 8601 en UTC).
 * @property-read string|null $paid_at
 * @property-read string|null $refunded_at
 * @property-read string $created_at
 */
final class Payment extends RayaObject
{
    public const OBJECT_NAME = 'payment';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    public const FAILURE_CODE_AMOUNT_MISMATCH = 'amount_mismatch';

    protected const INNER_CLASSES = [
        'bank_transfer' => Payment\BankTransfer::class,
        'payer' => Payment\Payer::class,
    ];
    protected const PLAIN_FIELDS = ['metadata'];

    /**
     * Reembolsa este pago completo, con el cliente del que vino (o con el estilo global, si lo
     * armaste a mano o lo deserializaste). Devuelve el pago actualizado: este objeto no cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function refund(#[\SensitiveParameter] array $options = []): Payment
    {
        Params::arguments('$payment->refund', func_num_args(), ['options']);
        $id = $this->rawValue('id');
        if (!is_string($id) || $id === '') {
            throw new \ValueError('Este Payment no tiene id: no se puede reembolsar.');
        }
        return (new PaymentService($this->resolveRequestor()))->refund($id, $options);
    }

    // --- Estilo global (Payment::create/retrieve/refundById); ver PaymentService. ---

    /**
     * Crea un pago con un CVU exclusivo. Usá un timeout de al menos 60 s (es el default).
     *
     * @param array{
     *     amount: int|float|string|\Stringable,
     *     description?: string|null,
     *     external_reference?: string|null,
     *     return_url?: string|null,
     *     metadata?: array<string, string>|\stdClass|null,
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
    ): Payment {
        Params::arguments('Payment::create', func_num_args(), ['params', 'options']);
        return (new PaymentService(GlobalState::requestor()))->create($params, $options);
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $paymentId,
        #[\SensitiveParameter]
        array $options = [],
    ): Payment {
        Params::arguments('Payment::retrieve', func_num_args(), ['paymentId', 'options']);
        return (new PaymentService(GlobalState::requestor()))->retrieve($paymentId, $options);
    }

    /**
     * Reembolsa un pago por id, con el estilo global. Desde un pago que ya tenés, usá
     * `$payment->refund()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function refundById(
        string $paymentId,
        #[\SensitiveParameter]
        array $options = [],
    ): Payment {
        Params::arguments('Payment::refundById', func_num_args(), ['paymentId', 'options']);
        return (new PaymentService(GlobalState::requestor()))->refund($paymentId, $options);
    }
}
