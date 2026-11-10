<?php

declare(strict_types=1);

namespace RayaCash\ConnectOrder;

use RayaCash\RayaObject;

/**
 * A dónde vuelve quien paga desde checkout_url. Siempre las dos claves, null si no la mandaste.
 *
 * @property-read string|null $success Después de pagar (con auto_return 'approved', solo).
 * @property-read string|null $failure Si el pago falla o vence.
 */
final class BackUrls extends RayaObject {}

// Checkout\BackUrls, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la 2.0.0
// (ver Internal\Deprecation).
\class_alias(BackUrls::class, \RayaCash\Checkout\BackUrls::class);
