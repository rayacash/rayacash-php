<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Internal\Retry;
use RayaCash\Internal\Timing;
use RayaCash\Tests\Support\TestCase;

/**
 * Cuándo y cuánto esperar para reintentar: Retry-After, backoff y la política (test_retry.py).
 *
 * TestCase deja el jitter fijo en el máximo (1.0) y el reloj del SDK en $this->now.
 */
final class RetryTest extends TestCase
{
    /** Tue, 14 Nov 2023 22:13:20 GMT: el «ahora» de los tests con fechas. */
    private const NOW = 1_700_000_000.0;

    /** @return iterable<string, array{string|null, float|null}> */
    public static function retryAfterSeconds(): iterable
    {
        yield 'null' => [null, null];
        yield '""' => ['', null];
        yield '"  "' => ['  ', null];
        yield '"2"' => ['2', 2.0];
        yield '" 5 "' => [' 5 ', 5.0];
        yield '"abc"' => ['abc', null];
        yield '"-1"' => ['-1', null];
        // De Node (su fila undefined es este mismo null en PHP: Headers::get() da null).
        yield '"1.5"' => ['1.5', null];
        // PHP: lo que is_numeric() o un cast (float) aceptarían. Python y Node dan None/null.
        yield '"1e3"' => ['1e3', null];
        yield '"+5"' => ['+5', null];
        yield '"007"' => ['007', 7.0];
        // Bytes que no son UTF-8 (un proxy roto): no es un número ni una fecha.
        yield 'no UTF-8' => [chr(255) . '5', null];
    }

    #[DataProvider('retryAfterSeconds')]
    public function testParseRetryAfterSeconds(?string $header, ?float $expected): void
    {
        self::assertSame($expected, Retry::parseRetryAfter($header));
    }

    public function testParseRetryAfterHttpDate(): void
    {
        $this->now = self::NOW;
        $future = self::formatDate(1_700_000_030);
        $past = self::formatDate(1_699_999_000);
        self::assertSame('Tue, 14 Nov 2023 22:13:50 GMT', $future);
        self::assertSame(30.0, Retry::parseRetryAfter($future));
        self::assertSame(0.0, Retry::parseRetryAfter($past));
        // Sin zona se toma UTC, como email.utils.parsedate_to_datetime (Python le saca « -0000»).
        $naive = substr(self::formatDate(1_700_000_010), 0, -strlen(' GMT'));
        self::assertSame('Tue, 14 Nov 2023 22:13:30', $naive);
        self::assertSame(10.0, Retry::parseRetryAfter($naive));
        // De Node: el offset numérico, una fecha imposible y algo que no es una fecha.
        self::assertSame(10.0, Retry::parseRetryAfter('14 Nov 2023 23:13:30 +0100'));
        self::assertNull(Retry::parseRetryAfter('Tue, 31 Feb 2023 22:13:30 GMT'));
        self::assertNull(Retry::parseRetryAfter('mañana'));
    }

    /**
     * PHP: la fecha HTTP sale de una regex estricta (la de Node) más checkdate(), nunca de
     * strtotime(), que acepta casi cualquier cosa y corre las fechas imposibles (31 Feb → 3 Mar).
     * En todas estas filas Python da lo mismo; donde Python es más laxo (RFC 850, asctime, años
     * de 2 dígitos, zonas como EST, texto de más al final) no hay fila: es una divergencia
     * declarada.
     *
     * @return iterable<string, array{string, float|null}>
     */
    public static function strictHttpDates(): iterable
    {
        yield 'sin día de la semana' => ['14 Nov 2023 22:13:50 GMT', 30.0];
        yield 'sin espacio después de la coma' => ['Tue,14 Nov 2023 22:13:50 GMT', 30.0];
        yield 'otro día de la semana (no se mira)' => ['Mon, 14 Nov 2023 22:13:50 GMT', 30.0];
        yield 'en minúsculas' => ['tue, 14 nov 2023 22:13:50 gmt', 30.0];
        yield 'UTC' => ['Tue, 14 Nov 2023 22:13:50 UTC', 30.0];
        yield 'UT' => ['Tue, 14 Nov 2023 22:13:50 UT', 30.0];
        yield 'Z' => ['Tue, 14 Nov 2023 22:13:50 Z', 30.0];
        yield '-0300' => ['Tue, 14 Nov 2023 19:13:50 -0300', 30.0];
        yield '-0000' => ['Tue, 14 Nov 2023 22:13:50 -0000', 30.0];
        yield '+0530 (un offset con minutos)' => ['Wed, 15 Nov 2023 03:43:50 +0530', 30.0];
        yield 'sin segundos' => ['Tue, 14 Nov 2023 22:14 GMT', 40.0];
        yield 'minuto 59' => ['Tue, 14 Nov 2023 22:59:00 GMT', 2740.0];
        yield 'segundo 59' => ['Tue, 14 Nov 2023 22:13:59 GMT', 39.0];
        yield 'día de una cifra' => ['Fri, 1 Dec 2023 00:00:00 GMT', 1_388_800.0];
        yield '29 Feb de un año bisiesto' => ['Thu, 29 Feb 2024 00:00:00 GMT', 9_164_800.0];
        yield '29 Feb de un año común' => ['Wed, 29 Feb 2023 22:13:50 GMT', null];
        yield 'hora 24' => ['Tue, 14 Nov 2023 24:00:00 GMT', null];
        yield 'minuto 60' => ['Tue, 14 Nov 2023 22:60:00 GMT', null];
        yield 'segundo 60' => ['Tue, 14 Nov 2023 22:13:60 GMT', null];
        yield 'mes que no existe' => ['14 Nox 2023 22:13:50 GMT', null];
        // Una zona de 24 h o más no es una zona (datetime.timezone de Python la rechaza).
        yield '+2359' => ['Wed, 15 Nov 2023 22:14:49 +2359', 149.0];
        yield '-2359' => ['Mon, 13 Nov 2023 22:14:50 -2359', 30.0];
        yield '+2360' => ['Tue, 14 Nov 2023 22:13:50 +2360', null];
        yield '+2400' => ['Tue, 14 Nov 2023 22:13:50 +2400', null];
        yield '-2400' => ['Tue, 14 Nov 2023 22:13:50 -2400', null];
        // Un año de dos cifras, aunque venga con cuatro: de 0 a 68 es 20xx, y de 69 a 99, 19xx.
        yield 'año 0023' => ['Tue, 14 Nov 0023 22:13:50 GMT', 30.0];
        yield 'año 0068' => ['Tue, 14 Nov 0068 22:13:50 GMT', 1_420_156_830.0];
        yield 'año 0069' => ['Fri, 14 Nov 0069 22:13:50 GMT', 0.0];
        yield '29 Feb del año 0000 (2000)' => ['Tue, 29 Feb 0000 22:13:50 GMT', 0.0];
        yield 'año 0100' => ['Sun, 14 Nov 0100 22:13:50 GMT', 0.0];
        // Después de 2038: en PHP de 32 bits, un int no llega.
        yield 'año 2041' => ['Thu, 14 Nov 2041 22:13:20 GMT', 568_080_000.0];
        yield 'ISO 8601' => ['2023-11-14T22:13:50Z', null];
        yield 'strtotime: now' => ['now', null];
        yield 'strtotime: +30 seconds' => ['+30 seconds', null];
        yield 'strtotime: tomorrow' => ['tomorrow', null];
        yield 'strtotime: @unix' => ['@1700000030', null];
    }

    #[DataProvider('strictHttpDates')]
    public function testParseRetryAfterIsStrictWithDates(string $header, ?float $expected): void
    {
        $this->now = self::NOW;
        self::assertSame($expected, Retry::parseRetryAfter($header));
    }

    public function testBackoffGrowsExponentiallyWithACap(): void
    {
        // TestCase fija el jitter en el máximo (1.0).
        self::assertSame(
            [0.5, 1.0, 2.0, 4.0, 8.0, 8.0],
            array_map(Retry::backoffDelay(...), [1, 2, 3, 4, 5, 6]),
        );
    }

    public function testBackoffJitterHalvesAtMost(): void
    {
        Timing::$jitter = static fn(): float => 0.0;
        self::assertSame(1.0, Retry::backoffDelay(3));
    }

    public function testRetryAfterIsAFloor(): void
    {
        self::assertSame(5.0, Retry::retryDelay(1, 5.0));
        self::assertSame(4.0, Retry::retryDelay(4, 2.0));
        self::assertSame(0.5, Retry::retryDelay(1, null));
    }

    /** @return iterable<string, array{int, string, float|null, bool}> */
    public static function retryPolicy(): iterable
    {
        $rows = [
            [409, 'IDEMPOTENCY_KEY_IN_USE', 2.0, true],
            [409, 'IDEMPOTENCY_KEY_REUSED', null, false],
            [409, 'PAYMENT_NOT_REFUNDABLE', null, false],
            [429, 'RATE_LIMITED', 30.0, true],
            [429, 'RATE_LIMITED', 60.0, true],
            [429, 'RATE_LIMITED', 61.0, false],
            [429, 'RATE_LIMITED', null, true],
            [408, 'INTERNAL_ERROR', null, true],
            [500, 'INTERNAL_ERROR', null, true],
            [502, 'INTERNAL_ERROR', null, true],
            [503, 'BANK_UNAVAILABLE', null, true],
            [503, 'BANK_UNAVAILABLE', 3600.0, false],
            [503, 'CVU_UNAVAILABLE', 5.0, true],
            [503, 'TEMPORARILY_UNAVAILABLE', 5.0, true],
            [503, 'API_UNAVAILABLE', null, false],
            [504, 'INTERNAL_ERROR', null, true],
            [400, 'PARAMETER_INVALID', null, false],
            [401, 'API_KEY_INVALID', null, false],
            [404, 'NOT_FOUND', null, false],
            [422, 'INSUFFICIENT_BALANCE', null, false],
            [302, 'INVALID_RESPONSE', null, false],
        ];
        foreach ($rows as [$status, $code, $retryAfter, $expected]) {
            $wait = $retryAfter === null ? 'sin Retry-After' : "Retry-After {$retryAfter}";
            yield "{$status} {$code} ({$wait})" => [$status, $code, $retryAfter, $expected];
        }
    }

    #[DataProvider('retryPolicy')]
    public function testRetryPolicy(
        int $status,
        string $code,
        ?float $retryAfter,
        bool $expected,
    ): void {
        self::assertSame($expected, Retry::shouldRetryResponse($status, $code, $retryAfter));
    }

    /** email.utils.formatdate(t, usegmt=True) de Python: la fecha HTTP (IMF-fixdate). */
    private static function formatDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }
}
