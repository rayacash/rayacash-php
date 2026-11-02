<?php

declare(strict_types=1);

namespace RayaCash\HttpClient;

/**
 * No llegó una respuesta completa: un timeout, un corte o un error de red. Tirala desde tu
 * HttpClientInterface; el SDK la convierte en ApiConnectionException (TIMEOUT o NETWORK_ERROR)
 * y reintenta si corresponde.
 */
final class TransportException extends \RuntimeException
{
    private function __construct(
        private readonly string $detail,
        private readonly bool $timeout,
        private readonly bool $retryable,
        ?\Throwable $previous,
    ) {
        parent::__construct($detail, 0, $previous);
    }

    /** El intento se pasó del timeout. Siempre se puede reintentar. */
    public static function timeout(string $detail = 'timeout', ?\Throwable $previous = null): self
    {
        return new self($detail, true, true, $previous);
    }

    /**
     * Un corte o un error de red. Con $retryable en false (un error de la request misma, como un
     * header inválido) el SDK no reintenta: daría lo mismo.
     */
    public static function network(
        string $detail,
        bool $retryable = true,
        ?\Throwable $previous = null,
    ): self {
        return new self($detail, false, $retryable, $previous);
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** Lo que pasó, para el mensaje del error: «curl 7: Failed to connect…». */
    public function getDetail(): string
    {
        return $this->detail;
    }
}
