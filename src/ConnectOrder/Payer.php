<?php

declare(strict_types=1);

namespace RayaCash\ConnectOrder;

use RayaCash\RayaObject;

/**
 * Quién transfirió, con los datos que informa su entidad.
 *
 * @property-read string|null $name
 * @property-read string|null $tax_id
 * @property-read string|null $account CBU o CVU de origen: si reembolsás el pago, la plata vuelve
 *     acá.
 */
final class Payer extends RayaObject {}

// Checkout\Payer, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la 2.0.0
// (ver Internal\Deprecation).
\class_alias(Payer::class, \RayaCash\Checkout\Payer::class);
