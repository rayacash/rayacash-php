<?php

declare(strict_types=1);

namespace RayaCash\User;

use RayaCash\RayaObject;

/**
 * El negocio de la cuenta, como lo ve quien paga en la página de un pago.
 *
 * @property-read string $name El nombre visible en la página de pago.
 * @property-read string|null $image La URL de la imagen del negocio, o null.
 */
final class Merchant extends RayaObject {}
