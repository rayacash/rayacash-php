<?php

declare(strict_types=1);

namespace RayaCash\Checkout;

use RayaCash\RayaObject;

/**
 * Un ítem de lo que se cobra, como se muestra en la página de pago.
 *
 * @property-read string $title
 * @property-read int $quantity
 * @property-read int|float $unit_price El precio unitario, en pesos.
 */
final class Item extends RayaObject {}
