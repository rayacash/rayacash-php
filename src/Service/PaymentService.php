<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\Payment;

/**
 * `$client->payments`: cobrar por transferencia, con un CVU exclusivo por pago.
 *
 * @final
 */
class PaymentService
{
    private const CREATE_PARAMS = [
        'amount',
        'description',
        'external_reference',
        'return_url',
        'metadata',
    ];

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Crea un pago pending con un CVU y un alias exclusivos (POST /v1/payments).
     *
     * Mostrale a quien paga bank_transfer->cvu y bank_transfer->alias, o mandalo a checkout_url.
     * Te avisamos con payment.succeeded cuando llega la transferencia. Puede tardar hasta 40 s si
     * damos de alta un CVU nuevo (el timeout por defecto es de 65 s).
     *
     * La idempotency_key es opcional pero recomendada: guardala con el pedido y reusala si
     * reintentás. Si no la pasás, generamos una que protege los reintentos de esta llamada.
     *
     * @param array{
     *     amount: int|float|string|\Stringable,
     *     description?: string|null,
     *     external_reference?: string|null,
     *     return_url?: string|null,
     *     metadata?: array<string, string>|\stdClass|null,
     * } $params amount en pesos, hasta 2 decimales, entre 0.01 y 99999999.99; description, hasta
     *     255 caracteres; external_reference, tu identificador (el número de pedido); return_url,
     *     a dónde vuelve quien paga desde checkout_url; metadata, hasta 20 pares de texto.
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(array $params, #[\SensitiveParameter] array $options = []): Payment
    {
        Params::arguments('payments->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'payments->create',
            $params,
            self::CREATE_PARAMS,
            "['amount' => 15000]",
        );
        $body = [
            'amount' => Encoding::canonicalAmount($values['amount'] ?? null),
            'description' => $values['description'] ?? null,
            'external_reference' => $values['external_reference'] ?? null,
            'return_url' => $values['return_url'] ?? null,
            // Siempre como objeto JSON: [] vacío sería una lista, y la API la rechaza.
            'metadata' => Params::metadata($values['metadata'] ?? null),
        ];
        return $this->requestor->requestAs(
            Payment::class,
            'POST',
            '/v1/payments',
            body: $body,
            options: RequestOptions::check($options),
        );
    }

    /**
     * Un pago de tu negocio (GET /v1/payments/:id).
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(string $paymentId, #[\SensitiveParameter] array $options = []): Payment
    {
        Params::arguments('payments->retrieve', func_num_args(), ['paymentId', 'options']);
        return $this->requestor->requestAs(
            Payment::class,
            'GET',
            '/v1/payments/' . Encoding::pathId('paymentId', $paymentId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Devuelve todo lo recibido a la cuenta desde la que se pagó (POST /v1/payments/:id/refund).
     *
     * Solo pagos succeeded, hasta 30 días después de paid_at. Es total: no hay reembolsos
     * parciales. Si la confirmación tarda, sale ApiException con getErrorCode() ===
     * 'BANK_UNAVAILABLE': reintentá más tarde con la misma idempotency_key (nunca se manda dos
     * veces) o esperá el webhook payment.refunded.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function refund(string $paymentId, #[\SensitiveParameter] array $options = []): Payment
    {
        Params::arguments('payments->refund', func_num_args(), ['paymentId', 'options']);
        return $this->requestor->requestAs(
            Payment::class,
            'POST',
            '/v1/payments/' . Encoding::pathId('paymentId', $paymentId) . '/refund',
            options: RequestOptions::check($options),
        );
    }
}
