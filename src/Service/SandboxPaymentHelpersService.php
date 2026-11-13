<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\ConnectOrder;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\Payment;

/**
 * `$client->testHelpers->payments`: simular la transferencia de quien paga un pago de prueba. Solo
 * en un sandbox, con su clave secreta de prueba (raya_sk_test_…): lo que en tu cuenta real hace el
 * banco.
 *
 * @final
 */
class SandboxPaymentHelpersService
{
    private const METHOD = 'testHelpers->payments->simulateTransfer';
    private const SIMULATE_PARAMS = ['amount'];

    // La API no usa Idempotency-Key acá: no se acepta ni entre los parámetros ni en las opciones.
    private const NO_IDEMPOTENCY_KEY
        = ': no lleva Idempotency-Key, un pago se simula una sola vez (repetirlo responde '
        . 'PAYMENT_NOT_PENDING).';

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Simula la transferencia de quien paga un pago de prueba
     * (POST /v1/test/payments/:id/simulate_transfer). Solo en un sandbox, con su clave secreta de
     * prueba (raya_sk_test_…): con tu clave real da PermissionDeniedException con getErrorCode()
     * === 'SANDBOX_ONLY'.
     *
     * Sin amount, por el monto exacto: el pago queda succeeded. Con un amount menor queda failed,
     * con failure_code en amount_mismatch y sin reembolso automático; igual o mayor, succeeded,
     * con lo simulado en amount_received. El saldo de prueba sube por lo recibido y los webhooks
     * salen como siempre, con livemode en false.
     *
     * Devuelve el Payment o, si $paymentId es el id de un pago de Raya Connect de una app del
     * sandbox, su ConnectOrder: distinguilos con instanceof. Si el pago ya no está pending (ya
     * simulado, reembolsado o vencido), da ConflictException con getErrorCode() ===
     * 'PAYMENT_NOT_PENDING' y el estado en getData()['status']; si no es de este sandbox,
     * NotFoundException con getErrorCode() === 'PAYMENT_NOT_FOUND'.
     *
     * No lleva Idempotency-Key (y las opciones no aceptan idempotency_key): un pago se simula una
     * sola vez. Si un corte de red deja sin respuesta un intento que sí llegó, el reintento da
     * PAYMENT_NOT_PENDING: consultá el pago con payments->retrieve() (una orden, con
     * connect->orders->retrieve() y el access_token del usuario de prueba).
     *
     * @param array{amount?: int|float|string|\Stringable|null} $params amount, lo que se simula
     *     recibir, en pesos con hasta 2 decimales (lo mismo que acepta payments->create); sin él,
     *     el monto exacto del pago.
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function simulateTransfer(
        string $paymentId,
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): Payment|ConnectOrder {
        Params::arguments(self::METHOD, func_num_args(), ['paymentId', 'params', 'options']);
        // Antes de Params::check(), que diría que va en las opciones.
        foreach (['idempotency_key', 'idempotencyKey'] as $name) {
            if (array_key_exists($name, $params)) {
                throw Params::withoutArguments(new \TypeError(
                    self::METHOD . " no acepta {$name} entre los parámetros"
                    . self::NO_IDEMPOTENCY_KEY,
                ));
            }
        }
        $values = Params::check(
            self::METHOD,
            $params,
            self::SIMULATE_PARAMS,
            "['amount' => 1500]",
        );
        $amount = $values['amount'] ?? null;
        // Sin amount no va body, como en un reembolso: la API simula el monto exacto del pago.
        $body = $amount === null ? null : ['amount' => Encoding::canonicalAmount($amount)];
        $path = '/v1/test/payments/' . Encoding::pathId('paymentId', $paymentId)
            . '/simulate_transfer';
        $opts = RequestOptions::check($options);
        if ($opts->has('idempotency_key')) {
            throw new \TypeError(
                self::METHOD . ' no acepta idempotency_key' . self::NO_IDEMPOTENCY_KEY,
            );
        }
        // La clase sale de "object" (payment o connect.order); Payment es solo el respaldo.
        return $this->requestor->requestAsOneOf(
            [Payment::class, ConnectOrder::class],
            'POST',
            $path,
            $opts,
            body: $body,
            idempotent: false,
        );
    }
}
