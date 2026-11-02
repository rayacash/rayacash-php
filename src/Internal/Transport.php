<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\HttpClient\CurlClient;
use RayaCash\HttpClient\HttpClientInterface;

/**
 * @internal De dónde sale el cliente HTTP propio del SDK (un CurlClient). Los tests cambian la
 * fábrica por una que falla: nunca salen a la red.
 */
final class Transport
{
    /** @var (\Closure(): HttpClientInterface)|null */
    public static ?\Closure $factory = null;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    public static function create(): HttpClientInterface
    {
        return self::$factory !== null ? (self::$factory)() : new CurlClient();
    }
}
