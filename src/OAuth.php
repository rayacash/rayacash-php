<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\OAuthService;

/**
 * Estilo global: `OAuth::token([…])`. Ver `$client->oauth->token()`.
 *
 * No necesita RayaCash::setApiKey(): la request no lleva Authorization, tu app se identifica con
 * client_id y client_secret.
 */
final class OAuth
{
    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * Canjea el code que llegó a tu redirect_uri por el access_token de la cuenta que conectó tu
     * aplicación. grant_type lo pone el SDK.
     *
     * @param array{
     *     client_id: string,
     *     client_secret: string,
     *     code: string,
     *     redirect_uri: string,
     * } $params
     * @param array{timeout?: int|float|null, max_network_retries?: int|null} $options
     */
    public static function token(
        #[\SensitiveParameter]
        array $params,
        #[\SensitiveParameter]
        array $options = [],
    ): OAuthToken {
        Params::arguments('OAuth::token', func_num_args(), ['params', 'options']);
        return (new OAuthService(GlobalState::requestor()))->token($params, $options);
    }
}
