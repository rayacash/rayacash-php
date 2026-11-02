<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Cómo viajan los parámetros: montos exactos en el body, la query de las listas, las
 * fechas, los ids en la ruta y la Idempotency-Key.
 *
 * La API parsea los montos como números exactos y rechaza los que tienen más de 2 decimales
 * (PARAMETER_INVALID). Por eso un monto viaja como número JSON canónico, nunca como string, y el
 * SDK no redondea nunca: si un float trae ruido (0.1 + 0.2), lo responde el servidor.
 */
final class Encoding
{
    private const DECIMAL = '/\A([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?\z/';
    private const NON_FINITE = '/\A[+-]?(?:inf|infinity|s?nan)\z/i';
    // Ningún monto real se acerca: corta antes de armar un string gigante con "1e999999".
    private const MAX_DIGITS = 1000;
    private const IDEMPOTENCY_KEY = '/\A[\x21-\x7e]{1,255}\z/';

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** El monto como número JSON exacto, sin redondear. El rango lo valida la API. */
    public static function canonicalAmount(mixed $value, string $name = 'amount'): RawNumber
    {
        if (is_bool($value)) {
            throw new \TypeError(
                "{$name} no puede ser un bool: mandá un número en pesos, como 1500 o 1500.5.",
            );
        }
        if (is_int($value)) {
            return new RawNumber((string) $value);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \ValueError(
                    "{$name} tiene que ser un número finito, no " . var_export($value, true) . '.',
                );
            }
            return new RawNumber(self::floatText($value));
        }
        if (is_string($value) || $value instanceof \Stringable) {
            return self::decimalFromText((string) $value, $name);
        }
        throw new \TypeError(
            "{$name} tiene que ser un número en pesos (int, float, string decimal o un decimal "
            . 'como BcMath\Number o BigDecimal), no ' . get_debug_type($value) . '.',
        );
    }

    /**
     * Un float en notación normal y sin ceros de más: los mismos dígitos que repr() de Python
     * (el decimal más corto que vuelve al mismo float), sin depender de serialize_precision.
     * -0.0 da "-0", como Python.
     */
    public static function floatText(float $value): string
    {
        return self::decimal(self::floatDigits($value), 'Un número')
            ?? throw new \LogicException('Un float finito siempre tiene dígitos.');
    }

    /**
     * Un float como lo escribe json.dumps de Python, para mostrarlo (RayaObject::__toString): los
     * dígitos de floatText() con la forma de repr(), que suma «.0» a los enteros y pasa a
     * exponente si el punto queda a más de 16 cifras o detrás de 4 ceros (1e+16, 1e-05); los no
     * finitos, Infinity y NaN. Nunca va en un body: ahí, floatText().
     */
    public static function floatRepr(float $value): string
    {
        if (!is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
        }
        $text = self::floatText($value);
        $sign = \str_starts_with($text, '-') ? '-' : '';
        $unsigned = \substr($text, strlen($sign));
        $all = \str_replace('.', '', $unsigned);
        $digits = \trim($all, '0');
        // Dónde queda el punto respecto de la primera cifra significativa (el decpt de Python):
        // 4 en 1500.5, 17 en 1e16, -6 en 0.0000001 y 0 en el cero.
        $point = strcspn($unsigned, '.') - strspn($all, '0');
        if ($point > -4 && $point <= 16) {
            return \str_contains($text, '.') ? $text : $text . '.0';
        }
        $exponent = $point - 1;
        return $sign . $digits[0] . (strlen($digits) > 1 ? '.' . \substr($digits, 1) : '')
            . sprintf('e%s%02d', $exponent < 0 ? '-' : '+', abs($exponent));
    }

    /**
     * El body de un POST: solo los parámetros que vinieron (la API rechaza los desconocidos y
     * los null), como un objeto JSON compacto en ASCII.
     *
     * @param array<array-key, mixed>|\stdClass $params
     */
    public static function body(array|\stdClass $params): string
    {
        $values = $params instanceof \stdClass ? get_object_vars($params) : $params;
        return Json::encode((object) array_filter(
            $values,
            static fn(mixed $value): bool => $value !== null,
        ));
    }

    /**
     * La query de una lista: solo los filtros que vinieron, como texto.
     *
     * @param array<array-key, mixed> $params
     * @return array<string, string>
     */
    public static function query(array $params): array
    {
        $query = [];
        foreach ($params as $name => $value) {
            $name = (string) $name;
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                throw new \TypeError("{$name} no puede ser un bool.");
            }
            if ($value instanceof \DateTimeInterface) {
                $query[$name] = self::datetime($value);
            } elseif (is_int($value) || is_string($value)) {
                $query[$name] = (string) $value;
            } else {
                throw new \TypeError("{$name} no acepta un " . get_debug_type($value) . '.');
            }
        }
        return $query;
    }

    /**
     * "?a=1&b=2" con rawurlencode (RFC 3986: el espacio es %20), o "" si no hay filtros. Nunca
     * http_build_query: escribe los bool como 1/0 y depende de arg_separator.output.
     *
     * @param array<array-key, string> $query
     */
    public static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
        }
        return $pairs === [] ? '' : '?' . \implode('&', $pairs);
    }

    /**
     * Como isoformat() de Python: 2026-10-23T00:00:00-03:00, con los microsegundos solo si hay.
     * Un DateTimeInterface siempre tiene zona.
     */
    public static function datetime(\DateTimeInterface $value): string
    {
        $micro = $value->format('u');
        return $value->format('Y-m-d\TH:i:s') . ($micro !== '000000' ? '.' . $micro : '')
            . $value->format('P');
    }

    /** Un id para la ruta, codificado: nunca deja una barra final ni un segmento vacío. */
    public static function pathId(string $name, string $value): string
    {
        if (Text::isBlank($value)) {
            throw new \ValueError("{$name} no puede estar vacío.");
        }
        // rawurlencode() deja los puntos, y curl resuelve los segmentos «.» y «..»: retrieve('.')
        // pediría /v1/transfers/ y refund('..'), POST /v1/refund. %2E no sirve: un proxy que
        // normaliza la URL lo vuelve a convertir en punto.
        if ($value === '.' || $value === '..') {
            throw new \ValueError("{$name} no puede ser \".\" ni \"..\".");
        }
        return rawurlencode($value);
    }

    public static function idempotencyKey(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \TypeError(
                'idempotency_key tiene que ser un string: generá un UUID v4 con '
                . 'RayaCash\Util\Uuid::v4() y guardalo junto a la operación antes de llamar.',
            );
        }
        if (\preg_match(self::IDEMPOTENCY_KEY, $value) !== 1) {
            throw new \ValueError(
                'idempotency_key tiene que tener de 1 a 255 caracteres ASCII visibles, sin '
                . 'espacios (por ejemplo, un UUID v4).',
            );
        }
        return $value;
    }

    /** El body crudo de un webhook: un string o algo que se convierte en uno (PSR-7). */
    public static function webhookPayload(mixed $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }
        if ($payload instanceof \Stringable) {
            return (string) $payload;
        }
        throw new \TypeError(
            'payload tiene que ser el body crudo (string), sin parsear: en PHP plano, '
            . "file_get_contents('php://input'); en Laravel y Symfony, \$request->getContent(); "
            . 'con PSR-7, (string) $request->getBody(). Llegó ' . get_debug_type($payload) . '.',
        );
    }

    private static function decimalFromText(string $text, string $name): RawNumber
    {
        $stripped = Text::strip($text);
        $canonical = $stripped === null ? null : self::decimal($stripped, $name);
        if ($canonical !== null) {
            return new RawNumber($canonical);
        }
        if ($stripped !== null && \preg_match(self::NON_FINITE, $stripped) === 1) {
            throw new \ValueError("{$name} tiene que ser un número finito, no {$text}.");
        }
        $quoted = json_encode(
            $text,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        throw new \ValueError(
            "{$name} {$quoted} no es un número: usá punto decimal y nada más, como \"1500.50\".",
        );
    }

    /**
     * El decimal en notación normal y sin ceros de más ("1.5e3" → "1500"); null si no es un
     * número. Es el algoritmo de Node, sobre el texto: exacto, sin el redondeo a 28 dígitos de
     * Decimal. El exponente se calcula como float, igual que Number(): uno enorme da INF y corta
     * con "demasiados dígitos".
     */
    private static function decimal(string $text, string $name): ?string
    {
        if (\preg_match(self::DECIMAL, $text, $match) !== 1) {
            return null;
        }
        $sign = $match[1];
        $int = $match[2];
        $frac = $match[3] ?? '';
        $exp = $match[4] ?? '';
        if ($int === '' && $frac === '') {
            return null;
        }
        $significant = ltrim($int . $frac, '0');
        if ($significant === '') {
            return $sign === '-' ? '-0' : '0';
        }
        $digits = rtrim($significant, '0');
        $shift = (float) $exp - strlen($frac) + strlen($significant) - strlen($digits);
        $point = strlen($digits) + $shift;
        if (\max($point, strlen($digits) - $point) > self::MAX_DIGITS) {
            throw new \ValueError("{$name} tiene demasiados dígitos.");
        }
        $shift = (int) $shift;
        $point = (int) $point;
        if ($shift >= 0) {
            $plain = $digits . str_repeat('0', $shift);
        } elseif ($point > 0) {
            $plain = \substr($digits, 0, $point) . '.' . \substr($digits, $point);
        } else {
            $plain = '0.' . str_repeat('0', -$point) . $digits;
        }
        return $sign === '-' ? '-' . $plain : $plain;
    }

    /**
     * Los dígitos más cortos que vuelven al mismo float ("1500.5", "1.0E-7", "-0.0"): con
     * serialize_precision = -1 (el valor por defecto) es var_export; si no, el primer %e que
     * vuelve al mismo float (%e no depende del locale, a diferencia de %f).
     */
    private static function floatDigits(float $value): string
    {
        if (ini_get('serialize_precision') === '-1') {
            return var_export($value, true);
        }
        if ($value === 0.0) {
            // %e pierde el signo de -0.0; 1 / -0.0 es -INF.
            return fdiv(1.0, $value) < 0 ? '-0' : '0';
        }
        for ($precision = 0; $precision < 16; $precision++) {
            $text = sprintf("%.{$precision}e", $value);
            if ((float) $text === $value) {
                return $text;
            }
            // En una potencia de 2 el hueco de arriba es el doble que el de abajo: el decimal
            // más corto puede ser el vecino que se aleja del cero, no el más cercano, y es el
            // que elige repr(). Son los dígitos más uno ("1299" → "1300"), sumados en el texto:
            // en PHP de 32 bits, 16 dígitos no entran en un int. Van acá, sin un método aparte:
            // con Xdebug, una llamada en cada vuelta duplica lo que tarda la cobertura en el
            // barrido de floats de EncodingTest.
            $at = (int) \strpos($text, 'e');
            $digits = \str_replace(['-', '.'], '', \substr($text, 0, $at));
            $head = rtrim($digits, '9');
            $last = $head === '' ? 1 : (int) \substr($head, -1) + 1;
            $next = ($value < 0 ? '-' : '') . \substr($head, 0, -1) . $last
                . str_repeat('0', strlen($digits) - strlen($head)) . 'e'
                . ((int) \substr($text, $at + 1) - $precision);
            if ((float) $next === $value) {
                return $next;
            }
        }
        return sprintf('%.16e', $value);
    }
}
