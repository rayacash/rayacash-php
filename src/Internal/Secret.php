<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Una clave secreta guardada fuera de cualquier objeto: el texto vive en un WeakMap
 * estático y este objeto es un token vacío. Así la clave nunca aparece en var_dump, print_r,
 * var_export, (array), json_encode, serialize ni en los dumps de los frameworks.
 */
final class Secret
{
    // Los prefijos que se conservan al enmascarar: la clave secreta y el access_token de una
    // cuenta conectada, cada uno con su par de prueba (los de un sandbox y sus usuarios de
    // prueba).
    private const PREFIXES = ['raya_sk_live_', 'raya_sk_test_', 'raya_at_live_', 'raya_at_test_'];

    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    private function __construct() {}

    public static function of(#[\SensitiveParameter] string $value): self
    {
        $secret = new self();
        self::values()[$secret] = $value;
        return $secret;
    }

    /** El texto: solo para validarlo y mandarlo en el header Authorization. */
    public function reveal(): string
    {
        return self::values()[$this] ?? '';
    }

    /**
     * raya_sk_live_…AbCd (o raya_at_live_…AbCd, o sus pares de prueba, raya_sk_test_…AbCd y
     * raya_at_test_…AbCd), como mask_key de Python; null si está vacía.
     */
    public function masked(): ?string
    {
        $key = $this->reveal();
        if ($key === '') {
            return null;
        }
        $prefix = '';
        foreach (self::PREFIXES as $candidate) {
            if (\str_starts_with($key, $candidate)) {
                $prefix = $candidate;
                break;
            }
        }
        $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($key);
        if (count($chars) <= strlen($prefix) + 8) {
            return $prefix . '…';
        }
        return $prefix . '…' . \implode('', array_slice($chars, -4));
    }

    /** @return array{key: string|null} */
    public function __debugInfo(): array
    {
        return ['key' => $this->masked()];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException(
            'La clave secreta no se serializa: armá el cliente en cada proceso.',
        );
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException(
            'La clave secreta no se serializa: armá el cliente en cada proceso.',
        );
    }

    /** @codeCoverageIgnore No corre: es privado para que nadie clone la clave. */
    private function __clone() {}

    /** @return \WeakMap<self, string> */
    private static function values(): \WeakMap
    {
        return self::$values ??= new \WeakMap();
    }
}
