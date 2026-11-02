<?php

declare(strict_types=1);

namespace RayaCash\HttpClient;

use RayaCash\Internal\Text;

/**
 * Los headers de una respuesta, de solo lectura y sin distinguir mayúsculas (HTTP/2 los manda en
 * minúscula): `$headers->get('Raya-Request-Id')` o `$headers['raya-request-id']`. Un header
 * repetido queda con sus valores unidos por ", ".
 *
 * @implements \ArrayAccess<string, string>
 * @implements \IteratorAggregate<string, string>
 */
final class Headers implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var array<string, string> En minúscula. */
    private array $values = [];

    /** @param array<string, string|int|list<string>> $headers */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $name = Text::asciiLower((string) $name);
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new \TypeError(
                        "El header {$name} tiene que ser un string o un int, no "
                        . get_debug_type($item) . '.',
                    );
                }
                $item = (string) $item;
                $this->values[$name] = isset($this->values[$name])
                    ? $this->values[$name] . ', ' . $item
                    : $item;
            }
        }
    }

    public function get(string $name): ?string
    {
        return $this->values[Text::asciiLower($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->values[Text::asciiLower($name)]);
    }

    /** @return array<string, string> Con los nombres en minúscula. */
    public function toArray(): array
    {
        return $this->values;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    public function offsetGet(mixed $offset): ?string
    {
        return is_string($offset) ? $this->get($offset) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Los headers de una respuesta son de solo lectura.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Los headers de una respuesta son de solo lectura.');
    }

    /** @return \ArrayIterator<string, string> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    public function count(): int
    {
        return count($this->values);
    }
}
