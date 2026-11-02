<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Las variables de entorno: $_ENV, después $_SERVER y por último getenv(). Un valor
 * vacío cuenta como ausente.
 *
 * $_ENV primero porque Symfony Dotenv lo llena sin putenv(); $_SERVER después porque ahí llegan
 * los env[] de FPM (el php.ini de producción deja $_ENV vacío). Un cliente HTTP no puede pisar
 * estas variables: sus headers llegan como HTTP_*.
 */
final class Env
{
    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    public static function get(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        // Algunos hostings ponen getenv() en disable_functions: ahí quedan $_ENV y $_SERVER.
        $value = \function_exists('getenv') ? getenv($name) : false;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
