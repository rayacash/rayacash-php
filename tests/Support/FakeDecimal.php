<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

/**
 * Un decimal exacto como BcMath\Number (PHP 8.4+) o Brick\Math\BigDecimal: lo único que el SDK
 * usa de ellos es su texto. Sirve en cualquier versión de PHP, sin dependencias, como el
 * FakeDecimal de los tests de Node.
 */
final class FakeDecimal implements \Stringable
{
    public function __construct(private readonly string $text) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
