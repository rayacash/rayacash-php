<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Requestor;

/**
 * `$client->connect`: Raya Connect. `$client->connect->orders` cobra a nombre de las cuentas que
 * conectaron tu aplicación, con tu comisión (con el access_token de cada una), y
 * `$client->connect->payouts` les envía pesos desde tu saldo a los vendedores de tu plataforma
 * (con tu clave secreta).
 *
 * En la 1.1.0 era `$client->checkout` (CheckoutService): ese nombre sigue andando como alias
 * deprecado de este mismo objeto hasta la 2.0.0.
 *
 * @final
 */
class ConnectService
{
    /** `$client->connect->orders->create/retrieve/refund()`: con el access_token de la cuenta. */
    public readonly ConnectOrderService $orders;

    /** `$client->connect->payouts->create/retrieve/list()`: con tu clave secreta. */
    public readonly ConnectPayoutService $payouts;

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(Requestor $requestor)
    {
        $this->orders = new ConnectOrderService($requestor);
        $this->payouts = new ConnectPayoutService($requestor);
    }
}

// CheckoutService, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la 2.0.0
// (ver Internal\Deprecation).
\class_alias(ConnectService::class, CheckoutService::class);
