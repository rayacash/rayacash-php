<?php

declare(strict_types=1);

namespace RayaCash;

/**
 * El saldo de un cliente de Raya PSP: `$client->psp->customers->retrieveBalance($customerId)`.
 *
 * Para transferir en su nombre tienen que alcanzar los dos saldos, el tuyo y el suyo: por eso
 * transferable es su saldo hasta tu saldo disponible, y 0 si no está active.
 *
 * @property-read 'psp.customer_balance' $object
 * @property-read string $customer El id del cliente (pspc_…).
 * @property-read int|float $available Su saldo, en pesos (el balance del cliente).
 * @property-read int|float $transferable Lo que podés transferir ahora en su nombre: su saldo, sin
 *     pasar tu saldo disponible; 0 si no está active. No tiene en cuenta su límite mensual: una
 *     transferencia a una cuenta fuera de Raya que lo supera responde 422 LIMIT_EXCEEDED.
 * @property-read string $currency Siempre 'ARS'.
 */
final class PspCustomerBalance extends RayaObject
{
    public const OBJECT_NAME = 'psp.customer_balance';
}
