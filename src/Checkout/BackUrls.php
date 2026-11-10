<?php

declare(strict_types=1);

namespace RayaCash\Checkout;

use RayaCash\ConnectOrder;
use RayaCash\Internal\Deprecation;

// El nombre de ConnectOrder\BackUrls en la 1.1.0: un alias deprecado de la misma clase, hasta la
// 2.0.0 (ver Internal\Deprecation).
Deprecation::alias(BackUrls::class, ConnectOrder\BackUrls::class);

if (\false) {
    /**
     * Nunca se declara: el nombre es un alias de ConnectOrder\BackUrls.
     *
     * @deprecated Desde la 1.2.0: usá ConnectOrder\BackUrls, que es la misma clase. Se elimina en
     *     la 2.0.0.
     */
    final class BackUrls extends ConnectOrder\BackUrls {}
}
