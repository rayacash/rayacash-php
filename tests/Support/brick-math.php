<?php

declare(strict_types=1);

// brick/math de mentira (el SDK no lo pide, y en 8.1 a 8.3 no hay BcMath\Number): como el de
// verdad, BigDecimal::of('2.50') es \Stringable y \JsonSerializable, y jsonSerialize() da su
// texto, un string que la API rechazaría como monto. Json lo tiene que mandar como número. Lo
// carga EncodingTest con require_once: no es una clase de RayaCash\Tests (PSR-4).

namespace Brick\Math;

abstract class BigNumber implements \JsonSerializable, \Stringable
{
    final protected function __construct(private readonly string $text) {}

    public function __toString(): string
    {
        return $this->text;
    }

    public function jsonSerialize(): string
    {
        return $this->text;
    }
}

final class BigDecimal extends BigNumber
{
    public static function of(string $value): self
    {
        return new self($value);
    }
}
