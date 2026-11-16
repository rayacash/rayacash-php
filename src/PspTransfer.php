<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\PspTransferService;

/**
 * Una transferencia de un cliente de Raya PSP: una que recibió en su CVU (inbound) o una que
 * enviaste en su nombre (outbound).
 *
 * amount es lo que se movió en el saldo del cliente; fee, la comisión, que se descuenta de tu
 * saldo (0 entre clientes de tu cuenta), y net_amount, amount menos fee. Es el mismo objeto que
 * llega en los webhooks psp.transfer.*. No aparece en `$client->transfers` ni emite transfer.*.
 * direction y status pueden sumar valores: tu código tiene que ignorarlos.
 *
 * @property-read string $id pspt_ y 24 letras o números.
 * @property-read 'psp.transfer' $object
 * @property-read string $customer El id del cliente (pspc_…).
 * @property-read 'inbound'|'outbound' $direction inbound: la recibió. outbound: la enviaste en su
 *     nombre.
 * @property-read 'pending'|'completed'|'failed'|'reversed' $status pending: la enviaste a otra
 *     entidad y todavía se está procesando. completed: se acreditó. failed: no se pudo completar.
 *     reversed: estaba completed y la entidad de destino la revirtió. En los dos últimos, te
 *     devolvemos amount al saldo del cliente y al tuyo.
 * @property-read int|float $amount Lo que se movió en el saldo del cliente, en pesos.
 * @property-read int|float $fee La comisión, que se descuenta de tu saldo: 0 entre clientes de tu
 *     cuenta. Si falla o se revierte, te la devolvemos y este campo se mantiene.
 * @property-read int|float $net_amount amount menos fee: en una enviada, lo que recibió el
 *     destino; en una recibida, lo que sumó tu saldo.
 * @property-read string $currency Siempre 'ARS'.
 * @property-read string $account El CVU del cliente que se usó: el de origen en una enviada y el
 *     de destino en una recibida.
 * @property-read PspTransfer\Counterparty $counterparty La otra cuenta.
 * @property-read string|null $description En una enviada, 'Transferencia'; en una recibida de otra
 *     entidad, el concepto que informó (o null).
 * @property-read string|null $reference El id en la red de transferencias: solo en las de otra
 *     entidad completed o reversed (null entre cuentas de Raya).
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-10-24T15:12:40Z'.
 */
final class PspTransfer extends RayaObject
{
    public const OBJECT_NAME = 'psp.transfer';

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected const INNER_CLASSES = ['counterparty' => PspTransfer\Counterparty::class];

    // --- Estilo global (PspTransfer::create/retrieve/list); ver PspTransferService. ---

    /**
     * Transfiere pesos en nombre de un cliente, con tu clave secreta (RayaCash::setApiKey() o
     * api_key). La idempotency_key es obligatoria.
     *
     * @param array{
     *     customer: string,
     *     amount: int|float|string|\Stringable,
     *     destination: string,
     * } $params
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function create(
        array $params,
        #[\SensitiveParameter]
        array $options,
    ): PspTransfer {
        Params::arguments('PspTransfer::create', func_num_args(), ['params', 'options']);
        return (new PspTransferService(GlobalState::requestor()))->create($params, $options);
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $transferId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspTransfer {
        Params::arguments('PspTransfer::retrieve', func_num_args(), ['transferId', 'options']);
        return (new PspTransferService(GlobalState::requestor()))->retrieve(
            $transferId,
            $options,
        );
    }

    /**
     * @param array{
     *     customer?: string|null,
     *     direction?: 'inbound'|'outbound'|null,
     *     status?: 'pending'|'completed'|'failed'|'reversed'|null,
     *     created_gte?: string|\DateTimeInterface|null,
     *     created_lt?: string|\DateTimeInterface|null,
     *     limit?: int|null,
     *     cursor?: string|null,
     * } $params
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @return ListObject<PspTransfer>
     */
    public static function list(
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): ListObject {
        Params::arguments('PspTransfer::list', func_num_args(), ['params', 'options']);
        return (new PspTransferService(GlobalState::requestor()))->list($params, $options);
    }
}
