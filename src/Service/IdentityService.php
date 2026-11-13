<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Requestor;

/**
 * `$client->identity`: Identidad. `$client->identity->verifications` verifica la identidad de tus
 * clientes (DNI, prueba de vida y RENAPER), con tu clave secreta.
 *
 * @final
 */
class IdentityService
{
    /** `$client->identity->verifications->create/retrieve/list/cancel/files()`. */
    public readonly IdentityVerificationService $verifications;

    /** @internal Lo arma RayaClient. */
    public function __construct(Requestor $requestor)
    {
        $this->verifications = new IdentityVerificationService($requestor);
    }
}
