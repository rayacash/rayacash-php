<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal El reloj, las esperas y el jitter de los reintentos. Los tests reemplazan cada uno
 * con un closure para no dormir ni depender de la hora; reset() vuelve a los reales.
 */
final class Timing
{
    /** @var (\Closure(float): void)|null Espera esos segundos. */
    public static ?\Closure $sleep = null;

    /** @var (\Closure(): float)|null Un número en [0, 1). */
    public static ?\Closure $jitter = null;

    /** @var (\Closure(): float)|null Ahora, en segundos unix. */
    public static ?\Closure $now = null;

    /** @var (\Closure(): float)|null Un reloj que solo avanza, en segundos (para elapsed). */
    public static ?\Closure $monotonic = null;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    public static function sleep(float $seconds): void
    {
        if (self::$sleep !== null) {
            (self::$sleep)($seconds);
            return;
        }
        if ($seconds <= 0) {
            return;
        }
        $left = ['seconds' => (int) $seconds, 'nanoseconds' => (int) (fmod($seconds, 1.0) * 1e9)];
        // Una señal corta la espera antes de tiempo: se sigue con lo que falta, como Python.
        do {
            $left = time_nanosleep($left['seconds'], $left['nanoseconds']);
        } while (is_array($left));
    }

    public static function jitter(): float
    {
        if (self::$jitter !== null) {
            return (self::$jitter)();
        }
        // 53 bits, como random.random(): con PHP_INT_MAX los valores altos redondean a 1.0. Van
        // en dos partes de 27 y 26 bits, como en CPython: en PHP de 32 bits, 2 ** 53 no es un int.
        $high = random_int(0, 0x7ffffff);
        $low = random_int(0, 0x3ffffff);
        return ($high * 67108864 + $low) / 9007199254740992;
    }

    public static function now(): float
    {
        return self::$now !== null ? (self::$now)() : microtime(true);
    }

    public static function monotonic(): float
    {
        return self::$monotonic !== null ? (self::$monotonic)() : (float) hrtime(true) / 1e9;
    }

    public static function reset(): void
    {
        self::$sleep = null;
        self::$jitter = null;
        self::$now = null;
        self::$monotonic = null;
    }
}
