<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Utilidades de texto: el strip() de Python (con espacios Unicode), mayúsculas y
 * minúsculas ASCII (strtolower depende del locale en PHP 8.1) y listas para los mensajes.
 */
final class Text
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';
    // Lo que saca str.strip() de Python (str.isspace()): con \s faltarían U+001C a U+001F.
    private const SPACE = '[\x09-\x0d\x1c-\x20\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}'
        . '\x{2029}\x{202f}\x{205f}\x{3000}]';
    // Los espacios del principio y los del final, estos desde el primero de su tramo: sin el
    // (?<!…), sin JIT (pcre.jit=0, o SELinux que no lo deja) cada espacio de un tramo del medio
    // lo volvería a recorrer hasta el final, y un header de 8 KB tardaría casi un segundo.
    private const EDGES = '/\A' . self::SPACE . '++|(?<!' . self::SPACE . ')' . self::SPACE
        . '++\z/u';

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** Sin los espacios de los bordes, los mismos que saca str.strip(); null si no es UTF-8. */
    public static function strip(string $text): ?string
    {
        return \preg_replace(self::EDGES, '', $text);
    }

    /** Vacío o solo espacios. Un texto que no es UTF-8 válido no cuenta como vacío. */
    public static function isBlank(string $text): bool
    {
        return self::strip($text) === '';
    }

    public static function asciiLower(string $text): string
    {
        return \strtr($text, self::UPPER, self::LOWER);
    }

    public static function asciiUpper(string $text): string
    {
        return \strtr($text, self::LOWER, self::UPPER);
    }

    /**
     * "a", "a y b", "a, b y c".
     *
     * @param non-empty-list<string> $names
     */
    public static function listNames(array $names): string
    {
        $last = array_pop($names);
        return $names === [] ? $last : \implode(', ', $names) . ' y ' . $last;
    }

    /**
     * La pista para un nombre en camelCase cuyo snake_case es válido: « (¿quisiste decir
     * api_key?)». Vacía si no hay pista.
     *
     * @param list<string> $allowed
     */
    public static function snakeHint(string $name, array $allowed): string
    {
        $snake = self::asciiLower((string) \preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
        return $snake !== $name && \in_array($snake, $allowed, true)
            ? " (¿quisiste decir {$snake}?)"
            : '';
    }

    /** El nombre de una clase sin su namespace: Counterparty, no RayaCash\Transfer\Counterparty. */
    public static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');
        return $slash === false ? $class : \substr($class, $slash + 1);
    }

    /**
     * El texto con una U+FFFD por cada secuencia que no es UTF-8, como errors="replace" de
     * Python, salvo en tres casos donde Python pone una por byte (3 o 4) y acá va una sola: un
     * surrogate codificado (ED A0 80), una forma overlong (E0 80 80) y lo que pasa de U+10FFFF
     * (F4 90 80 80). Solo cambia el texto de algo que ya llegó roto.
     */
    public static function utf8(string $text): string
    {
        if (\preg_match('//u', $text) === 1) {
            return $text;
        }
        $json = (string) json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($json);
        return is_string($decoded) ? $decoded : '';
    }
}
