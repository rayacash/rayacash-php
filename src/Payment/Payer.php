<?php

declare(strict_types=1);

namespace RayaCash\Payment;

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
