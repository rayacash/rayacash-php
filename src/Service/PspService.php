<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Requestor;

/**
 * `$client->psp`: Raya PSP. `$client->psp->customers` les da a los clientes de tu empresa una
 * cuenta con un CVU a su nombre, `$client->psp->transfers` transfiere en su nombre y
 * `$client->psp->balance` da tu saldo y el de tus clientes, con tu clave secreta.
 *
 * Solo para cuentas de empresa con Raya PSP activado.
 *
 * @final
 */
class PspService
{
    /**
     * `$client->psp->customers->create/retrieve/list/retrieveBalance/block/unblock/close/
     * recreate()`.
     */
    public readonly PspCustomerService $customers;

    /** `$client->psp->transfers->create/retrieve/list()`. */
    public readonly PspTransferService $transfers;

    /** `$client->psp->balance->retrieve()`. */
    public readonly PspBalanceService $balance;

    /** @internal Lo arma RayaClient. */
    public function __construct(Requestor $requestor)
    {
        $this->customers = new PspCustomerService($requestor);
        $this->transfers = new PspTransferService($requestor);
        $this->balance = new PspBalanceService($requestor);
    }
}
