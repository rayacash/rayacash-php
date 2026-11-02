<?php

declare(strict_types=1);

namespace RayaCash\Util;

/**
 * Un UUID v4 para la idempotency_key: `$clave = Uuid::v4();`. Guardalo con la operación ANTES
 * de llamar y reusalo en cada reintento: así nunca se ejecuta dos veces.
 */
final class Uuid
{
    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx, en minúscula (RFC 9562), con random_bytes(). */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-' . \substr($hex, 12, 4) . '-'
            . \substr($hex, 16, 4) . '-' . \substr($hex, 20);
    }
}
