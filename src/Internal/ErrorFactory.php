<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\Exception\ApiException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\ConflictException;
use RayaCash\Exception\IdempotencyException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\NotFoundException;
use RayaCash\Exception\PermissionDeniedException;
use RayaCash\Exception\RateLimitException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\HttpClient\Headers;

/**
 * @internal El error de una respuesta que no es 2xx. La clase sale del status HTTP, como pide la
 * API («si llega un código que no conocés, tratalo según su código HTTP»); el código, del body.
 */
final class ErrorFactory
{
    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** @return class-string<RayaException> */
    public static function errorClass(int $status, ?string $code): string
    {
        return match (true) {
            \in_array($status, [400, 405, 413], true) => InvalidRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => $code !== null && \str_starts_with($code, 'IDEMPOTENCY_KEY_')
                ? IdempotencyException::class
                : ConflictException::class,
            $status === 422 => UnprocessableEntityException::class,
            $status === 429 => RateLimitException::class,
            $status === 408 || $status >= 500 => ApiException::class,
            // Los 5xx ya salieron arriba: queda cualquier otro 4xx.
            $status >= 400 => InvalidRequestException::class,
            default => ApiException::class,
        };
    }

    /**
     * El código y el mensaje de una respuesta sin el objeto error (HTML del edge, body vacío…).
     *
     * @return array{string, string}
     */
    public static function fallback(int $status): array
    {
        if ($status === 429) {
            return [
                'RATE_LIMITED',
                'Superaste el límite de requests. Esperá los segundos que indica Retry-After y '
                . 'reintentá.',
            ];
        }
        if ($status === 408 || $status >= 500) {
            return [
                'INTERNAL_ERROR',
                "Falló algo del lado de Raya (HTTP {$status}). Reintentá en unos minutos; en los "
                . 'POST, con la misma Idempotency-Key.',
            ];
        }
        return ['INVALID_RESPONSE', "La API respondió algo inesperado (HTTP {$status})."];
    }

    public static function fromResponse(
        int $status,
        Headers $headers,
        string $body,
        ?string $idempotencyKey,
    ): RayaException {
        $json = null;
        if ($body !== '') {
            try {
                // Como response.text en Python: un byte que no es UTF-8 (de un proxy, por
                // ejemplo) no se lleva el código del error ni la decisión de reintentar.
                $json = json_decode(Text::utf8($body), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }

        $code = null;
        $message = null;
        $data = null;
        $envelope = $json instanceof \stdClass ? get_object_vars($json)['error'] ?? null : null;
        if ($envelope instanceof \stdClass) {
            $fields = get_object_vars($envelope);
            $code = is_string($fields['code'] ?? null) ? $fields['code'] : null;
            $message = is_string($fields['message'] ?? null) ? $fields['message'] : null;
            $data = ($fields['data'] ?? null) instanceof \stdClass
                ? Hydrator::plainObject($fields['data'])
                : null;
        }
        if ($code === null) {
            [$code, $fallback] = self::fallback($status);
            $message = $message === null || $message === '' ? $fallback : $message;
        }

        $class = self::errorClass($status, $code);
        return new $class(
            $message ?? '',
            errorCode: $code,
            data: $data,
            httpStatus: $status,
            requestId: $headers->get('raya-request-id'),
            httpHeaders: $headers,
            httpBody: $body,
            jsonBody: Hydrator::plain($json),
            idempotencyKey: $idempotencyKey,
            retryAfter: Retry::parseRetryAfter($headers->get('retry-after')),
        );
    }
}
