<?php

declare(strict_types=1);

namespace RayaCash\Exception;

use RayaCash\HttpClient\Headers;

/**
 * Base de todos los errores del SDK.
 *
 * La clase sale del status HTTP, como pide la API («si llega un código que no conocés, tratalo
 * según su código HTTP»). Para decidir qué hacer, usá getErrorCode() (estable), nunca
 * getMessage() (texto para personas, puede cambiar). getCode() es el status HTTP (0 si no hubo
 * respuesta).
 */
class RayaException extends \Exception
{
    private ?string $errorCode;

    /** @var array<array-key, mixed>|null */
    private ?array $data;

    private ?int $httpStatus;
    private ?string $requestId;
    private Headers $httpHeaders;
    private ?string $httpBody;
    private mixed $jsonBody;
    private ?string $idempotencyKey;
    private ?float $retryAfter;

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
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
        $this->errorCode = $errorCode;
        $this->data = $data;
        $this->httpStatus = $httpStatus;
        $this->requestId = $requestId;
        $this->httpHeaders = $httpHeaders ?? new Headers();
        $this->httpBody = $httpBody;
        $this->jsonBody = $jsonBody;
        $this->idempotencyKey = $idempotencyKey;
        $this->retryAfter = $retryAfter;
    }

    /** El código estable, como INSUFFICIENT_BALANCE: decidí con esto. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Los detalles del error (field, status, reason…) o null.
     *
     * @return array<array-key, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** El Raya-Request-Id (req_…): mandalo si escribís a soporte. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** Los headers de la respuesta (vacíos si no hubo respuesta). */
    public function getHttpHeaders(): Headers
    {
        return $this->httpHeaders;
    }

    /** El body crudo de la respuesta. */
    public function getHttpBody(): ?string
    {
        return $this->httpBody;
    }

    /** El body parseado, si era JSON (con arrays comunes). */
    public function getJsonBody(): mixed
    {
        return $this->jsonBody;
    }

    /** La Idempotency-Key con la que se mandó el POST: reintentá con la misma. */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** Los segundos que pidió esperar el servidor (header Retry-After). */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /** El mismo getMessage(), como user_message en Python y userMessage en Node. */
    public function getUserMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * Lo que Laravel suma al log de la excepción.
     *
     * @return array{error_code: string|null, request_id: string|null, http_status: int|null}
     */
    public function context(): array
    {
        return [
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
            'http_status' => $this->httpStatus,
        ];
    }

    /**
     * El formato de PHP, con el resumen de Python en vez del mensaje solo:
     * «RayaCash\Exception\InvalidRequestException: Request req_abc: … (PARAMETER_INVALID) in …».
     */
    public function __toString(): string
    {
        $text = sprintf(
            "%s: %s in %s:%d\nStack trace:\n%s",
            static::class,
            $this->summary(),
            $this->getFile(),
            $this->getLine(),
            $this->getTraceAsString(),
        );
        $previous = $this->getPrevious();
        return $previous === null ? $text : $previous . "\n\nNext " . $text;
    }

    /**
     * var_dump muestra lo que sirve para entender el error: sin headers, bodies ni la traza.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'http_status' => $this->httpStatus,
            'request_id' => $this->requestId,
            'idempotency_key' => $this->idempotencyKey,
            'retry_after' => $this->retryAfter,
            'data' => $this->data,
        ];
    }

    /**
     * Se serializa sin la traza ni la excepción anterior (pueden traer argumentos).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'error_code' => $this->errorCode,
            'data' => $this->data,
            'http_status' => $this->httpStatus,
            'request_id' => $this->requestId,
            'http_headers' => $this->httpHeaders->toArray(),
            'http_body' => $this->httpBody,
            'json_body' => $this->jsonBody,
            'idempotency_key' => $this->idempotencyKey,
            'retry_after' => $this->retryAfter,
        ];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        $string = static fn(string $key): ?string => is_string($data[$key] ?? null)
            ? $data[$key]
            : null;
        $this->message = $string('message') ?? '';
        $this->file = $string('file') ?? '';
        $this->line = is_int($data['line'] ?? null) ? $data['line'] : 0;
        $this->errorCode = $string('error_code');
        $this->data = is_array($data['data'] ?? null) ? $data['data'] : null;
        $this->httpStatus = is_int($data['http_status'] ?? null) ? $data['http_status'] : null;
        $this->code = $this->httpStatus ?? 0;
        $this->requestId = $string('request_id');
        $headers = $data['http_headers'] ?? null;
        $this->httpHeaders = new Headers(is_array($headers) ? self::headerValues($headers) : []);
        $this->httpBody = $string('http_body');
        $this->jsonBody = $data['json_body'] ?? null;
        $this->idempotencyKey = $string('idempotency_key');
        $retryAfter = $data['retry_after'] ?? null;
        $this->retryAfter = is_int($retryAfter) || is_float($retryAfter)
            ? (float) $retryAfter
            : null;
    }

    /** Como str() en Python: «Request req_abc: mensaje (CÓDIGO)». */
    private function summary(): string
    {
        $text = $this->getMessage() !== '' ? $this->getMessage() : '<sin mensaje>';
        if ($this->errorCode !== null && $this->errorCode !== '') {
            $text = "{$text} ({$this->errorCode})";
        }
        if ($this->requestId !== null && $this->requestId !== '') {
            $text = "Request {$this->requestId}: {$text}";
        }
        return $text;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function headerValues(array $headers): array
    {
        $values = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $values[(string) $name] = $value;
            }
        }
        return $values;
    }
}
