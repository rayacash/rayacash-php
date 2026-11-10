<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Requestor;

/**
 * `$client->connect`: Raya Connect, cobrar a nombre de las cuentas que conectaron tu aplicación,
 * con tu comisión. Hoy, `$client->connect->orders`.
 *
 * En la 1.1.0 era `$client->checkout` (CheckoutService): ese nombre sigue andando como alias
 * deprecado de este mismo objeto hasta la 2.0.0.
 *
 * @final
 */
class ConnectService
{
    /** `$client->connect->orders->create/retrieve/refund()`. */
    public readonly ConnectOrderService $orders;

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(Requestor $requestor)
    {
        $this->orders = new ConnectOrderService($requestor);
    }
}

// CheckoutService, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la 2.0.0
// (ver Internal\Deprecation).
\class_alias(ConnectService::class, CheckoutService::class);
