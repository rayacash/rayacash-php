<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Lo que el requestor le pregunta a su dueño (un RayaClient o el estilo global) en
 * cada request.
 */
interface RequestorConfig
{
    public function apiKey(): ?Secret;

    public function apiBase(): string;

    /** Segundos por intento (para conectar, el menor entre este y 10). */
    public function timeout(): float;

    public function maxNetworkRetries(): int;

    public function userAgent(): string;

    public function http(): HttpClientManager;
}
