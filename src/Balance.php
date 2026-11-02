<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\BalanceService;

/**
 * Tu saldo disponible, en pesos.
 *
 * Ya tiene descontadas las transferencias que enviaste, aunque sigan pending.
 *
 * @property-read 'balance' $object
 * @property-read int|float $available Saldo disponible en pesos (puede ser negativo).
 * @property-read string $currency Siempre 'ARS'.
 */
final class Balance extends RayaObject
{
    public const OBJECT_NAME = 'balance';

    /**
     * Estilo global: `Balance::retrieve()`. Ver `$client->balance->retrieve()`.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(#[\SensitiveParameter] array $options = []): Balance
    {
        Params::arguments('Balance::retrieve', func_num_args(), ['options']);
        return (new BalanceService(GlobalState::requestor()))->retrieve($options);
    }
}
