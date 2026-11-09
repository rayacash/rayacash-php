<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Requestor;

/**
 * `$client->checkout`: cobrar a nombre de las cuentas que conectaron tu aplicación, con tu
 * comisión. Hoy, `$client->checkout->orders`.
 *
 * @final
 */
class CheckoutService
{
    /** `$client->checkout->orders->create/retrieve/refund()`. */
    public readonly CheckoutOrderService $orders;

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(Requestor $requestor)
    {
        $this->orders = new CheckoutOrderService($requestor);
    }
}
