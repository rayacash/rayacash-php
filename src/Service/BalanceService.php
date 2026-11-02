<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Balance;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;

/**
 * `$client->balance`: tu saldo disponible.
 *
 * @final
 */
class BalanceService
{
    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Tu saldo disponible en pesos (GET /v1/balance).
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(#[\SensitiveParameter] array $options = []): Balance
    {
        Params::arguments('balance->retrieve', func_num_args(), ['options']);
        return $this->requestor->requestAs(
            Balance::class,
            'GET',
            '/v1/balance',
            options: RequestOptions::check($options),
        );
    }
}
