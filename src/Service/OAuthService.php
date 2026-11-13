<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\OAuthToken;

/**
 * `$client->oauth`: canjear el code de una cuenta que conectó tu aplicación por su access_token.
 *
 * @final
 */
class OAuthService
{
    /** El único grant_type que existe: lo pone el SDK, no vos. */
    public const GRANT_TYPE_AUTHORIZATION_CODE = 'authorization_code';

    private const TOKEN_PARAMS = ['client_id', 'client_secret', 'code', 'redirect_uri'];

    // Qué es cada parámetro, para el error de uno que falta o no es un string.
    private const HINTS = [
        'client_id' => "el número de tu aplicación, como '7650337728110670', entre comillas",
        'client_secret' => 'el raya_cs_live_… de Developers › Apps, o el raya_cs_test_… de una '
            . 'app de un sandbox',
        'code' => 'el code que llegó a la query de tu redirect_uri',
        'redirect_uri' => 'la misma URL del link de autorización',
    ];

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Canjea el code que llegó a tu redirect_uri por el access_token de la cuenta que conectó tu
     * aplicación (POST /v1/oauth/token). grant_type lo pone el SDK.
     *
     * Es la única request sin Authorization: tu app se identifica con client_id y client_secret,
     * así que el RayaClient puede no tener clave (y las opciones no aceptan api_key). Tampoco
     * lleva Idempotency-Key: el code dura 10 minutos y se canjea una sola vez. Si falla con
     * OAUTH_GRANT_INVALID, ya se usó, venció o redirect_uri no coincide: la cuenta tiene que volver
     * a autorizar. Guardá access_token y user_id juntos, como guardás tu clave secreta: el token
     * no vence, y deja de valer cuando la cuenta desconecta tu app o la vuelve a autorizar.
     *
     * @param array{
     *     client_id: string,
     *     client_secret: string,
     *     code: string,
     *     redirect_uri: string,
     * } $params client_id, el número de tu aplicación (16 dígitos, como string); client_secret,
     *     el raya_cs_live_… de Developers › Apps (en una app de un sandbox, su raya_cs_test_…;
     *     solo en tu servidor); code, el de la query de tu redirect_uri; redirect_uri, exactamente
     *     la misma URL del link de autorización.
     * @param array{timeout?: int|float|null, max_network_retries?: int|null} $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function token(
        #[\SensitiveParameter]
        array $params,
        #[\SensitiveParameter]
        array $options = [],
    ): OAuthToken {
        Params::arguments('oauth->token', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'oauth->token',
            $params,
            self::TOKEN_PARAMS,
            "['client_id' => …, 'client_secret' => …, 'code' => …, 'redirect_uri' => …]",
        );
        // En el orden de la API, con grant_type en el medio.
        $body = [
            'client_id' => null,
            'client_secret' => null,
            'grant_type' => self::GRANT_TYPE_AUTHORIZATION_CODE,
            'code' => null,
            'redirect_uri' => null,
        ];
        foreach (self::TOKEN_PARAMS as $name) {
            $value = $values[$name] ?? null;
            if (!is_string($value) || $value === '') {
                throw new \TypeError(sprintf(
                    'oauth->token necesita %s como string (%s), no %s.',
                    $name,
                    self::HINTS[$name],
                    $value === '' ? 'uno vacío' : get_debug_type($value),
                ));
            }
            $body[$name] = $value;
        }
        $opts = RequestOptions::check($options);
        if ($opts->hasApiKey()) {
            throw new \TypeError(
                'oauth->token no acepta api_key en las opciones: no lleva Authorization, la app se '
                . 'identifica con client_id y client_secret.',
            );
        }
        if ($opts->has('idempotency_key')) {
            throw new \TypeError(
                'oauth->token no acepta idempotency_key: no lleva Idempotency-Key, el code se '
                . 'canjea una sola vez.',
            );
        }
        return $this->requestor->requestAs(
            OAuthToken::class,
            'POST',
            '/v1/oauth/token',
            $opts,
            body: $body,
            authenticated: false,
        );
    }
}
