<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\ConnectPayoutService;

/**
 * Un payout de Raya Connect: pesos que le enviás desde tu saldo a un vendedor de tu plataforma,
 * por CBU, CVU o alias.
 *
 * amount es lo que sale de tu saldo, processing_fee la comisión que te cobramos y net_amount lo
 * que recibe la cuenta de destino (amount menos processing_fee). Sale de la cuenta de tu negocio
 * (origin) y su id es el de la transferencia que lo representa en `$client->transfers` y en los
 * webhooks transfer.*: no hay eventos propios de payouts. Se autentica con tu clave secreta, no
 * con el access_token de una cuenta conectada. status puede sumar valores: tu código tiene que
 * ignorarlos.
 *
 * @property-read string $id El mismo id de la transferencia (Transfer) que lo representa.
 * @property-read 'connect.payout' $object
 * @property-read 'pending'|'completed'|'failed'|'reversed' $status pending: lo creaste y todavía
 *     se está procesando. completed: se acreditó en la cuenta de destino. failed: no se pudo
 *     completar. reversed: estaba completed y la entidad de destino lo revirtió. En los dos
 *     últimos te devolvemos amount entero, incluida la comisión.
 * @property-read int|float $amount Lo que salió de tu saldo, en pesos.
 * @property-read int|float $processing_fee La comisión de este payout, en pesos. Puede ser 0. Si
 *     falla o se revierte, te la devolvemos y este campo se mantiene.
 * @property-read int|float $net_amount Lo que recibe la cuenta de destino: amount menos
 *     processing_fee.
 * @property-read string $currency Siempre 'ARS'.
 * @property-read ConnectPayout\Origin $origin La cuenta de tu negocio desde la que sale.
 * @property-read ConnectPayout\Counterparty $counterparty A quién le enviaste: el titular que
 *     encontramos al validar el destino.
 * @property-read string|null $reference El id en la red de transferencias: null mientras está
 *     pending, si falló y cuando el destino es una cuenta de Raya.
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-10-24T17:40:06Z'.
 */
final class ConnectPayout extends RayaObject
{
    public const OBJECT_NAME = 'connect.payout';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected const INNER_CLASSES = [
        'origin' => ConnectPayout\Origin::class,
        'counterparty' => ConnectPayout\Counterparty::class,
    ];

    // --- Estilo global (ConnectPayout::create/retrieve/list); ver ConnectPayoutService. ---

    /**
     * Envía pesos desde tu saldo a un CBU, CVU o alias, con tu clave secreta (RayaCash::setApiKey()
     * o api_key). La idempotency_key es obligatoria.
     *
     * @param array{amount: int|float|string|\Stringable, destination: string} $params
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
    ): ConnectPayout {
        Params::arguments('ConnectPayout::create', func_num_args(), ['params', 'options']);
        return (new ConnectPayoutService(GlobalState::requestor()))->create($params, $options);
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $payoutId,
        #[\SensitiveParameter]
        array $options = [],
    ): ConnectPayout {
        Params::arguments('ConnectPayout::retrieve', func_num_args(), ['payoutId', 'options']);
        return (new ConnectPayoutService(GlobalState::requestor()))->retrieve($payoutId, $options);
    }

    /**
     * @param array{
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
     * @return ListObject<ConnectPayout>
     */
    public static function list(
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): ListObject {
        Params::arguments('ConnectPayout::list', func_num_args(), ['params', 'options']);
        return (new ConnectPayoutService(GlobalState::requestor()))->list($params, $options);
    }
}
