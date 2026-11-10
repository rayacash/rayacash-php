<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Checkout;
use RayaCash\Internal\Deprecation;

// El nombre de ConnectOrderService en la 1.1.0 (`$client->checkout->orders`): un alias deprecado
// de la misma clase, hasta la 2.0.0 (ver Internal\Deprecation).
Deprecation::alias(CheckoutOrderService::class, ConnectOrderService::class);

if (\false) {
    /**
     * Nunca se declara: el nombre es un alias de ConnectOrderService. Para tu IDE y PHPStan, que
     * no ven el alias, describe la API de la 1.1.0: sus métodos devuelven un Checkout.
     *
     * @deprecated Desde la 1.2.0: usá ConnectOrderService, que es la misma clase. Se elimina en
     *     la 2.0.0.
     * @final
     */
    class CheckoutOrderService extends ConnectOrderService
    {
        // Sin phpdoc: PHPStan toma el de ConnectOrderService (los tipos de params y options).

        public function create(array $params, array $options = []): Checkout {}

        public function retrieve(string $orderId, array $options = []): Checkout {}

        public function refund(string $orderId, array $options = []): Checkout {}
    }
}
