<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Cuándo y cuánto esperar para reintentar. Funciones puras: el requestor las usa en
 * su bucle.
 *
 * Política (api.md › «Qué hacer según la respuesta»):
 *
 * - Sin respuesta (corte, timeout): se reintenta. Los POST siempre llevan Idempotency-Key, así
 *   que nunca se ejecutan dos veces.
 * - 409 IDEMPOTENCY_KEY_IN_USE, 429, 408 y 5xx: se reintenta, esperando el Retry-After si viene.
 *   Si pide más de MAX_RETRY_AFTER (un reembolso puede pedir 3600 s), no se reintenta: el error
 *   sale con getRetryAfter() para que lo reprogrames.
 * - 503 API_UNAVAILABLE es un interruptor (mantenimiento): no se reintenta.
 * - Cualquier otro 4xx: no se hizo nada; reintentar da lo mismo.
 */
final class Retry
{
    public const INITIAL_DELAY = 0.5;
    public const MAX_DELAY = 8.0;
    public const MAX_RETRY_AFTER = 60.0;

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    // La fecha HTTP de RFC 9110 (IMF-fixdate), con el día de la semana opcional y sin zona = UTC,
    // como parsedate_to_datetime de Python. Nunca strtotime(): acepta casi cualquier cosa.
    private const HTTP_DATE = '/\A(?:[a-z]{3},\s*)?([0-9]{1,2})\s+([a-z]{3})\s+([0-9]{4})'
        . '\s+([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?(?:\s+(gmt|utc|ut|z|[+-][0-9]{4}))?\z/i';

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** Segundos del header Retry-After (también acepta la forma de fecha HTTP). */
    public static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $text = Text::strip($value) ?? '';
        if ($text === '') {
            return null;
        }
        if (\preg_match('/\A[0-9]+\z/', $text) === 1) {
            return (float) $text;
        }
        $when = self::parseHttpDate($text);
        return $when === null ? null : \max(0.0, $when - Timing::now());
    }

    /** Espera antes del reintento número $retryNumber (1, 2, …): exponencial con jitter. */
    public static function backoffDelay(int $retryNumber): float
    {
        $base = \min(self::INITIAL_DELAY * 2 ** ($retryNumber - 1), self::MAX_DELAY);
        return $base / 2 + $base / 2 * Timing::jitter();
    }

    public static function retryDelay(int $retryNumber, ?float $retryAfter): float
    {
        $delay = self::backoffDelay($retryNumber);
        return $retryAfter === null ? $delay : \max($delay, $retryAfter);
    }

    public static function shouldRetryResponse(int $status, ?string $code, ?float $retryAfter): bool
    {
        if ($status === 409) {
            $retryable = $code === 'IDEMPOTENCY_KEY_IN_USE';
        } elseif ($status === 503 && $code === 'API_UNAVAILABLE') {
            $retryable = false;
        } else {
            $retryable = $status === 408 || $status === 429 || $status >= 500;
        }
        if ($retryable && $retryAfter !== null && $retryAfter > self::MAX_RETRY_AFTER) {
            return false;
        }
        return $retryable;
    }

    /** Segundos unix de una fecha HTTP; null si no es una (o si es imposible, como 31 Feb). */
    private static function parseHttpDate(string $value): ?float
    {
        if (\preg_match(self::HTTP_DATE, $value, $match) !== 1) {
            return null;
        }
        $day = (int) $match[1];
        $month = self::MONTHS[Text::asciiLower($match[2])] ?? 0;
        $year = (int) $match[3];
        // Un año de dos cifras, aunque venga con cuatro ("0023"), como parsedate_to_datetime: de
        // 0 a 68 es 20xx y de 69 a 99, 19xx.
        if ($year < 100) {
            $year += $year > 68 ? 1900 : 2000;
        }
        $hour = (int) $match[4];
        $minute = (int) $match[5];
        $second = (int) ($match[6] ?? 0);
        $zone = Text::asciiLower($match[7] ?? '');
        $valid = $month > 0 && checkdate($month, $day, $year);
        if (!$valid || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $offset = 0;
        if (\str_starts_with($zone, '+') || \str_starts_with($zone, '-')) {
            $minutes = (int) \substr($zone, 1, 2) * 60 + (int) \substr($zone, 3, 2);
            if ($minutes >= 1440) {
                // Como datetime.timezone en Python: una zona de 24 h o más no es una fecha.
                return null;
            }
            $offset = $zone[0] === '-' ? -$minutes : $minutes;
        }
        // DateTimeImmutable y no gmmktime(): en PHP de 32 bits, un int no pasa de 2038.
        $utc = new \DateTimeImmutable(
            sprintf('%04d-%02d-%02dT%02d:%02d:%02dZ', $year, $month, $day, $hour, $minute, $second),
        );
        return (float) $utc->format('U') - $offset * 60;
    }
}
