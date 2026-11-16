<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\PspBalance;

/**
 * `$client->psp->balance`: tu saldo y el de tus clientes de Raya PSP.
 *
 * @final
 */
class PspBalanceService
{
    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Tu saldo disponible y la suma de los saldos de tus clientes, en pesos
     * (GET /v1/psp/balance). Lo que reciben tus clientes suma a tu saldo, y para transferir en
     * nombre de uno tienen que alcanzar los dos: tu saldo manda.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(#[\SensitiveParameter] array $options = []): PspBalance
    {
        Params::arguments('psp->balance->retrieve', func_num_args(), ['options']);
        return $this->requestor->requestAs(
            PspBalance::class,
            'GET',
            '/v1/psp/balance',
            options: RequestOptions::check($options),
        );
    }
}
