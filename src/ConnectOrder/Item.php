<?php

declare(strict_types=1);

namespace RayaCash\ConnectOrder;

use RayaCash\RayaObject;

/**
 * Un ítem de lo que se cobra, como se muestra en la página de pago.
 *
 * @property-read string $title
 * @property-read int $quantity
 * @property-read int|float $unit_price El precio unitario, en pesos.
 */
final class Item extends RayaObject {}

// Checkout\Item, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la 2.0.0
// (ver Internal\Deprecation).
\class_alias(Item::class, \RayaCash\Checkout\Item::class);
