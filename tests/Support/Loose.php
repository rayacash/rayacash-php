<?php

// Sin declare(strict_types=1) a propósito (php-cs-fixer lo saltea): así llama al SDK un proyecto
// sin strict_types, donde PHP convierte escalares (true → 1, 123 → "123") antes de entrar.

namespace RayaCash\Tests\Support;

final class Loose
{
    public static function call(callable $function, mixed ...$arguments): mixed
    {
        return $function(...$arguments);
    }
}
