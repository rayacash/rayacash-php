<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\HttpClient\CurlClient;
use RayaCash\HttpClient\HttpClientInterface;

/**
 * @internal El cliente HTTP de un RayaClient (o del estilo global): el que pasaste se usa tal
 * cual y nunca se cierra; si no pasaste ninguno, el SDK crea el suyo con la primera request y lo
 * reusa (mismas conexiones entre llamadas y reintentos).
 */
final class HttpClientManager
{
    private ?HttpClientInterface $owned = null;

    public function __construct(private readonly ?HttpClientInterface $user = null) {}

    public function client(): HttpClientInterface
    {
        return $this->user ?? ($this->owned ??= Transport::create());
    }

    /** Suelta el cliente propio (el próximo request crea otro); el que pasaste no se toca. */
    public function close(): void
    {
        $owned = $this->owned;
        $this->owned = null;
        if ($owned instanceof CurlClient) {
            $owned->close();
        }
    }
}
