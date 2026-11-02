<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal La configuración del estilo global, leída en cada request (como _GlobalConfig de
 * Python): cambiarla entre llamadas surte efecto enseguida, y sin clave asignada cada request lee
 * RAYA_SECRET_KEY.
 */
final class GlobalConfig implements RequestorConfig
{
    public function apiKey(): ?Secret
    {
        $key = GlobalState::apiKey();
        if ($key !== null) {
            return $key;
        }
        $env = Env::get('RAYA_SECRET_KEY');
        return $env === null ? null : Secret::of($env);
    }

    public function apiBase(): string
    {
        return GlobalState::apiBase();
    }

    public function timeout(): float
    {
        return (float) GlobalState::timeout();
    }

    public function maxNetworkRetries(): int
    {
        return GlobalState::maxNetworkRetries();
    }

    public function userAgent(): string
    {
        return UserAgent::build(GlobalState::appInfo());
    }

    public function http(): HttpClientManager
    {
        return GlobalState::httpManager();
    }
}
