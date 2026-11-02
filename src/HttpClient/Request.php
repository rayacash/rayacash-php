<?php

declare(strict_types=1);

namespace RayaCash\HttpClient;

use RayaCash\Internal\Secret;
use RayaCash\Internal\Text;

/**
 * Una request lista para mandar. La arma el SDK; tu HttpClientInterface la recibe.
 *
 * La clave secreta no es una propiedad: sale recién en headers() (el header Authorization), así
 * no aparece en var_dump, var_export, json_encode ni en las trazas de un error.
 */
final class Request
{
    public readonly string $method;

    /** La URL completa, con la query. */
    public readonly string $url;

    /** El body JSON; null si la request no lleva body. */
    public readonly ?string $body;

    /** Segundos para todo el intento. */
    public readonly float $timeout;

    /** Segundos para conectar (el menor entre 10 y el timeout). */
    public readonly float $connectTimeout;

    /** @var array<string, string> Sin Authorization. */
    private readonly array $headers;

    private readonly Secret $apiKey;

    /**
     * @internal La arma el SDK.
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method,
        string $url,
        array $headers,
        #[\SensitiveParameter]
        Secret $apiKey,
        ?string $body,
        float $timeout,
        float $connectTimeout,
    ) {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->apiKey = $apiKey;
        $this->body = $body;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Los headers a mandar: Authorization, Accept, User-Agent y, si corresponde, Content-Type e
     * Idempotency-Key.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey->reveal()] + $this->headers;
    }

    /** Un header, sin distinguir mayúsculas; null si no va. */
    public function header(string $name): ?string
    {
        $wanted = Text::asciiLower($name);
        foreach ($this->headers() as $header => $value) {
            if (Text::asciiLower($header) === $wanted) {
                return $value;
            }
        }
        return null;
    }

    /**
     * var_dump y print_r muestran la clave enmascarada (raya_sk_live_…AbCd) y no el body.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey->masked()] + $this->headers,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
        ];
    }
}
