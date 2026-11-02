<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\TransferService;

/**
 * Una transferencia: enviada (outbound) o recibida (inbound, un depósito).
 *
 * Es el mismo objeto que llega en los webhooks transfer.*. Los cobros de tus pagos no son
 * transferencias: se siguen en cada Payment. direction y status pueden sumar valores nuevos: tu
 * código tiene que ignorarlos.
 *
 * @property-read string $id
 * @property-read 'transfer' $object
 * @property-read 'inbound'|'outbound' $direction inbound: la recibiste. outbound: la enviaste.
 * @property-read 'pending'|'completed'|'failed'|'reversed' $status
 * @property-read int|float $amount El monto en pesos. En las recibidas, antes de la comisión.
 * @property-read int|float $fee La comisión que te cobramos.
 * @property-read int|float $net_amount amount menos fee: en las recibidas, lo que sumó tu saldo.
 * @property-read string $currency Siempre 'ARS'.
 * @property-read Transfer\Counterparty $counterparty
 * @property-read string|null $description
 * @property-read string|null $reference El id en la red de transferencias: null mientras está
 *     pending, si falló y en las transferencias entre cuentas de Raya.
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-10-24T13:58:12Z'.
 */
final class Transfer extends RayaObject
{
    public const OBJECT_NAME = 'transfer';

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected const INNER_CLASSES = ['counterparty' => Transfer\Counterparty::class];

    // --- Estilo global (Transfer::create/retrieve/list); ver TransferService para el detalle. ---

    /**
     * Envía pesos a un CBU, CVU o alias. La idempotency_key es obligatoria.
     *
     * @param array{amount: int|float|string|\Stringable, destination: string} $params
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function create(array $params, #[\SensitiveParameter] array $options): Transfer
    {
        Params::arguments('Transfer::create', func_num_args(), ['params', 'options']);
        return (new TransferService(GlobalState::requestor()))->create($params, $options);
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
    ): Transfer {
        Params::arguments('Transfer::retrieve', func_num_args(), ['transferId', 'options']);
        return (new TransferService(GlobalState::requestor()))->retrieve($transferId, $options);
    }

    /**
     * @param array{
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
     * @return ListObject<Transfer>
     */
    public static function list(
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): ListObject {
        Params::arguments('Transfer::list', func_num_args(), ['params', 'options']);
        return (new TransferService(GlobalState::requestor()))->list($params, $options);
    }
}
