<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Las opciones de una request, el último argumento de cada método: pisan las del
 * cliente (o las globales) solo en esa llamada. Las que valen null no cuentan. La clave queda
 * guardada como Secret; los valores se validan al preparar la request, en el orden de Python.
 */
final class RequestOptions
{
    private const KEYS = ['api_key', 'timeout', 'max_network_retries', 'idempotency_key'];

    /** @param array<string, mixed> $values */
    private function __construct(
        private readonly ?Secret $apiKey,
        private readonly ?string $apiKeyType,
        private readonly array $values,
    ) {}

    /** @param array<array-key, mixed> $options */
    public static function check(#[\SensitiveParameter] array $options): self
    {
        // retrieve($id, [$otraClave]): sin nombres, no se sabe qué es cada valor (y una clave
        // nunca se muestra), como un dict en Python.
        if ($options !== [] && array_is_list($options)) {
            throw new \TypeError(
                "options tiene que ser un array con nombres, como ['timeout' => 90], no una "
                . 'lista.',
            );
        }
        $values = [];
        foreach ($options as $key => $value) {
            $key = (string) $key;
            if (!\in_array($key, self::KEYS, true)) {
                throw new \TypeError(sprintf(
                    'options no acepta "%s"%s: las opciones son api_key, timeout, '
                    . 'max_network_retries e idempotency_key (solo en los POST).',
                    $key,
                    Text::snakeHint($key, self::KEYS),
                ));
            }
            if ($value !== null) {
                $values[$key] = $value;
            }
        }
        $apiKey = $values['api_key'] ?? null;
        unset($values['api_key']);
        return new self(
            is_string($apiKey) ? Secret::of($apiKey) : null,
            $apiKey === null || is_string($apiKey) ? null : get_debug_type($apiKey),
            $values,
        );
    }

    /** La clave de esta llamada, si vino (una que no es string recién falla acá, como Python). */
    public function apiKey(): ?Secret
    {
        if ($this->apiKeyType !== null) {
            throw new \TypeError(
                "La clave secreta tiene que ser un string, no {$this->apiKeyType}.",
            );
        }
        return $this->apiKey;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
}
