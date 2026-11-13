<?php

declare(strict_types=1);

namespace RayaCash\ConnectPayout;

use RayaCash\RayaObject;

/**
 * A quién le enviaste el payout: el titular que encontramos al validar el destino.
 *
 * @property-read string|null $name Nombre del titular.
 * @property-read string|null $tax_id CUIT o CUIL del titular: 11 dígitos, sin guiones.
 * @property-read string|null $account CBU o CVU de destino: 22 dígitos.
 * @property-read string|null $alias Alias de la cuenta, si lo conocemos.
 */
final class Counterparty extends RayaObject {}
