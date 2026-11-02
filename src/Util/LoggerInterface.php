<?php

declare(strict_types=1);

namespace RayaCash\Util;

/**
 * Lo mínimo de un logger para RayaCash::setLogger(), si no usás PSR-3: info() y debug(). Un
 * logger PSR-3 (Monolog, Laravel, Symfony) sirve tal cual, sin implementar esta interfaz.
 *
 * Cada línea llega en logfmt como mensaje, con sus campos (method, path, status…) como contexto.
 */
interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void;
}
