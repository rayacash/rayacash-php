<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Requestor;

/**
 * `$client->testHelpers`: lo que solo existe en un sandbox, con su clave secreta de prueba
 * (raya_sk_test_…). Con tu clave real, la API responde PermissionDeniedException con
 * getErrorCode() === 'SANDBOX_ONLY'.
 *
 * @final
 */
class SandboxHelpersService
{
    /** `$client->testHelpers->payments->simulateTransfer()`: simular un pago de prueba. */
    public readonly SandboxPaymentHelpersService $payments;

    /** @internal Lo arma RayaClient. */
    public function __construct(Requestor $requestor)
    {
        $this->payments = new SandboxPaymentHelpersService($requestor);
    }
}
