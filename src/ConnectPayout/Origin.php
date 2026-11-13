<?php

declare(strict_types=1);

namespace RayaCash\ConnectPayout;

use RayaCash\RayaObject;

/**
 * La cuenta de tu negocio desde la que sale el payout.
 *
 * @property-read string $account CVU de origen: 22 dígitos.
 * @property-read string|null $alias Alias de esa cuenta, si tiene.
 * @property-read string $name El nombre con el que la ve quien recibe la transferencia.
 */
final class Origin extends RayaObject {}
