<?php

declare(strict_types=1);

namespace RayaCash\HttpClient;

/**
 * La respuesta HTTP que devuelve un HttpClientInterface: status, headers y el body entero.
 *
 * `new Response(200, ['Content-Type' => 'application/json'], '{"object":"balance", …}')`.
 */
final class Response
{
    public readonly Headers $headers;

    /** @param Headers|array<string, string|int|list<string>> $headers */
    public function __construct(
        public readonly int $status,
        Headers|array $headers = [],
        public readonly string $body = '',
    ) {
        $this->headers = $headers instanceof Headers ? $headers : new Headers($headers);
    }
}
