<?php

declare(strict_types=1);

namespace RayaCash\Checkout;

use RayaCash\RayaObject;

/**
 * A dónde vuelve quien paga desde checkout_url. Siempre las dos claves, null si no la mandaste.
 *
 * @property-read string|null $success Después de pagar (con auto_return 'approved', solo).
 * @property-read string|null $failure Si el pago falla o vence.
 */
final class BackUrls extends RayaObject {}
