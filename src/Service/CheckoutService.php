<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Deprecation;

// El nombre de ConnectService en la 1.1.0 (`$client->checkout`): un alias deprecado de la misma
// clase, hasta la 2.0.0 (ver Internal\Deprecation).
Deprecation::alias(CheckoutService::class, ConnectService::class);

if (\false) {
    /**
     * Nunca se declara: el nombre es un alias de ConnectService. Para tu IDE y PHPStan, que no
     * ven el alias, describe la API de la 1.1.0: `$client->checkout->orders` es un
     * CheckoutOrderService.
     *
     * @deprecated Desde la 1.2.0: usá ConnectService, que es la misma clase. Se elimina en la
     *     2.0.0.
     * @final
     */
    class CheckoutService extends ConnectService
    {
        /** `$client->checkout->orders->create/retrieve/refund()`. */
        public readonly CheckoutOrderService $orders;
    }
}
