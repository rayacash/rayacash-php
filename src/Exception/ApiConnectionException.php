<?php

declare(strict_types=1);

namespace RayaCash\Exception;

use RayaCash\HttpClient\Headers;

/**
 * No llegó respuesta: un timeout o un corte (getErrorCode() es TIMEOUT o NETWORK_ERROR). No
 * sabemos si la operación se hizo: reintentá más tarde con la misma Idempotency-Key.
 */
class ApiConnectionException extends RayaException
{
    private bool $shouldRetry;

    /** @param array<array-key, mixed>|null $data */
    public function __construct(
        string $message = '',
        ?string $errorCode = null,
        ?array $data = null,
        ?int $httpStatus = null,
        ?string $requestId = null,
        ?Headers $httpHeaders = null,
        ?string $httpBody = null,
        mixed $jsonBody = null,
        ?string $idempotencyKey = null,
        ?float $retryAfter = null,
        ?\Throwable $previous = null,
        bool $shouldRetry = false,
    ) {
        parent::__construct(
            $message,
            $errorCode,
            $data,
            $httpStatus,
            $requestId,
            $httpHeaders,
            $httpBody,
            $jsonBody,
            $idempotencyKey,
            $retryAfter,
            $previous,
        );
        $this->shouldRetry = $shouldRetry;
    }

    /** Si se puede reintentar sin riesgo: los POST siempre llevan Idempotency-Key. */
    public function shouldRetry(): bool
    {
        return $this->shouldRetry;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return parent::__debugInfo() + ['should_retry' => $this->shouldRetry];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return parent::__serialize() + ['should_retry' => $this->shouldRetry];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        $this->shouldRetry = ($data['should_retry'] ?? false) === true;
    }
}
