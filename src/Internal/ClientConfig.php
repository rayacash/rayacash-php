<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal La configuración de un RayaClient: queda fija al construirlo (como _ClientConfig de
 * Python). Solo el cliente HTTP propio se crea después, con la primera request.
 */
final class ClientConfig implements RequestorConfig
{
    public function __construct(
        private readonly ?Secret $apiKey,
        private readonly string $apiBase,
        private readonly float $timeout,
        private readonly int $maxNetworkRetries,
        private readonly string $userAgent,
        private readonly HttpClientManager $http,
    ) {}

    public function apiKey(): ?Secret
    {
        return $this->apiKey;
    }

    public function apiBase(): string
    {
        return $this->apiBase;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function maxNetworkRetries(): int
    {
        return $this->maxNetworkRetries;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function http(): HttpClientManager
    {
        return $this->http;
    }
}
