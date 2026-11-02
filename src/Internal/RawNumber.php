<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Un número JSON ya canónico (1500.5, 1000): su texto va tal cual en el body, como el
 * Decimal de Python y el RawNumber de Node.
 */
final class RawNumber
{
    public function __construct(public readonly string $text) {}
}
