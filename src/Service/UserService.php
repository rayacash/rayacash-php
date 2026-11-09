<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\User;

/**
 * `$client->users`: los datos de las cuentas que conectaron tu aplicación.
 *
 * Con un RayaClient armado con el access_token de esa cuenta (raya_at_live_…), no con tu clave.
 *
 * @final
 */
class UserService
{
    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Una cuenta que conectó tu aplicación (GET /v1/users/:id).
     *
     * $userId es el user_id que vino con su access_token, o 'me'. Cualquier otro id da
     * NotFoundException con getErrorCode() === 'USER_NOT_FOUND': solo ves las cuentas conectadas,
     * y cada una con su propio token.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(string $userId, #[\SensitiveParameter] array $options = []): User
    {
        Params::arguments('users->retrieve', func_num_args(), ['userId', 'options']);
        return $this->requestor->requestAs(
            User::class,
            'GET',
            '/v1/users/' . Encoding::pathId('userId', $userId),
            options: RequestOptions::check($options),
        );
    }
}
