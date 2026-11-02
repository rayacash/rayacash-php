<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal JSON compacto en ASCII, con los mismos bytes que json.dumps de Python: sin espacios,
 * en el orden dado, "/" sin escapar y DEL como \u007f. Los montos van como número JSON exacto y
 * los floats, en notación normal (nunca 1.0E+25), sin depender de serialize_precision.
 */
final class Json
{
    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \ValueError(
                    'No se puede mandar ' . var_export($value, true) . ' en JSON.',
                );
            }
            return Encoding::floatText($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof RawNumber) {
            return $value->text;
        }
        // Antes que JsonSerializable: BigDecimal se serializa como string y la API lo rechaza.
        // is_a() con el nombre nunca dispara el autoload (ni falla si la clase no existe).
        $decimal = is_object($value)
            && (is_a($value, 'BcMath\Number') || is_a($value, 'Brick\Math\BigNumber'));
        if ($decimal) {
            return Encoding::canonicalAmount($value, 'Un decimal')->text;
        }
        if ($value instanceof \JsonSerializable) {
            return self::encode($value->jsonSerialize());
        }
        if (is_array($value)) {
            return array_is_list($value) ? self::list($value) : self::object($value);
        }
        if ($value instanceof \stdClass) {
            return self::object(get_object_vars($value));
        }
        throw new \TypeError('No se puede mandar un ' . get_debug_type($value) . ' en JSON.');
    }

    /** @param list<mixed> $items */
    private static function list(array $items): string
    {
        $parts = array_map(static fn(mixed $item): string => self::encode($item), $items);
        return '[' . \implode(',', $parts) . ']';
    }

    /** @param array<array-key, mixed> $fields */
    private static function object(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = self::string((string) $key) . ':' . self::encode($value);
        }
        return '{' . \implode(',', $parts) . '}';
    }

    private static function string(string $value): string
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \ValueError(
                'No se puede mandar texto que no es UTF-8 válido: convertilo con '
                . 'mb_convert_encoding() antes de llamar.',
                0,
                $e,
            );
        }
        return \str_replace("\x7f", '\u007f', $json);
    }
}
