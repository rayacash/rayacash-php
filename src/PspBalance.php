<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\PspBalanceService;

/**
 * Tu saldo y el de tus clientes de Raya PSP: `$client->psp->balance->retrieve()`.
 *
 * Lo que reciben tus clientes se acredita en tu cuenta y suma a tu saldo: available es tu saldo
 * disponible (el mismo de `$client->balance->retrieve()`) y customers_balance, la suma de los
 * saldos de tus clientes. Para transferir en nombre de un cliente tienen que alcanzar los dos:
 * tu saldo manda.
 *
 * @property-read 'psp.balance' $object
 * @property-read int|float $available Tu saldo disponible en pesos (puede ser negativo).
 * @property-read int|float $customers_balance La suma de los saldos de tus clientes, también los
 *     que tienen el CVU dado de baja.
 * @property-read string $currency Siempre 'ARS'.
 */
final class PspBalance extends RayaObject
{
    public const OBJECT_NAME = 'psp.balance';

    /**
     * Estilo global: `PspBalance::retrieve()`. Ver `$client->psp->balance->retrieve()`.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(#[\SensitiveParameter] array $options = []): PspBalance
    {
        Params::arguments('PspBalance::retrieve', func_num_args(), ['options']);
        return (new PspBalanceService(GlobalState::requestor()))->retrieve($options);
    }
}
