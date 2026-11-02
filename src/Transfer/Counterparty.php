<?php

declare(strict_types=1);

namespace RayaCash\Transfer;

use RayaCash\RayaObject;

/**
 * La otra cuenta de una transferencia, con los datos que informa su entidad.
 *
 * @property-read string|null $name Nombre del titular.
 * @property-read string|null $tax_id CUIT o CUIL del titular: 11 dígitos, sin guiones.
 * @property-read string|null $account CBU o CVU: 22 dígitos.
 * @property-read string|null $alias Alias de la cuenta, si lo conocemos.
 */
final class Counterparty extends RayaObject {}
