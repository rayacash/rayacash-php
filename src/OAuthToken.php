<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\Secret;

/**
 * El access_token de una cuenta de Raya que conectó tu aplicación: la respuesta de
 * `$client->oauth->token(…)`.
 *
 * Guardalo junto a user_id como guardás tu clave secreta (solo en tu servidor): es la api_key de
 * un RayaClient para cobrar a nombre de esa cuenta (`$connected->connect->orders`) y leer sus
 * datos (`$connected->users`). No vence: deja de valer cuando la cuenta desconecta tu app
 * (ACCESS_TOKEN_REVOKED) o cuando vuelve a autorizarla (se emite uno nuevo).
 *
 * Es un secreto: `(string) $token`, var_dump y print_r lo muestran enmascarado
 * (raya_at_live_…G9hJ), para que no termine entero en un log. `$token->access_token`, toArray() y
 * json_encode($token) lo dan entero, para guardarlo.
 *
 * @property-read 'oauth_token' $object
 * @property-read string $access_token raya_at_live_…: va en Authorization: Bearer, como una clave.
 * @property-read string $token_type Siempre 'Bearer'.
 * @property-read string $scope Los permisos que dio la cuenta, separados por espacio
 *     ('orders profile').
 * @property-read string $user_id El id de la cuenta conectada: el de `users->retrieve()` y el
 *     account de sus pagos de Connect.
 * @property-read int|null $expires_in Hoy siempre null: el token no vence. Viene por las
 *     integraciones portadas de Mercado Pago.
 * @property-read string|null $refresh_token Hoy siempre null: no hay refresh. Viene por las
 *     integraciones portadas de Mercado Pago.
 */
final class OAuthToken extends RayaObject
{
    public const OBJECT_NAME = 'oauth_token';

    /** El JSON ordenado de RayaObject, con el access_token enmascarado. */
    public function __toString(): string
    {
        $shown = clone $this;
        $token = $shown->rawValue('access_token');
        if (is_string($token) && $token !== '') {
            $shown['access_token'] = Secret::of($token)->masked();
        }
        return $shown->plainString();
    }

    /**
     * Lo que muestran var_dump y print_r: los campos, con el access_token enmascarado.
     *
     * @return array<array-key, mixed>
     */
    public function __debugInfo(): array
    {
        $values = parent::__debugInfo();
        $token = $values['access_token'] ?? null;
        if (is_string($token) && $token !== '') {
            $values['access_token'] = Secret::of($token)->masked();
        }
        return $values;
    }

    /** El __toString de RayaObject, sobre este objeto (lo llama el clon enmascarado). */
    private function plainString(): string
    {
        return parent::__toString();
    }
}
