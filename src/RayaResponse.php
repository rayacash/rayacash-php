<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\HttpClient\Headers;

/**
 * La respuesta HTTP detrás de un objeto (`$obj->getLastResponse()`): status, headers y body
 * crudos, el JSON ya parseado y los ids para soporte.
 */
final class RayaResponse
{
    /** El Raya-Request-Id (req_…). En un replay idempotente es uno nuevo. */
    public readonly ?string $requestId;

    /**
     * @param \stdClass $data El JSON ya parseado.
     * @param string|null $idempotencyKey La Idempotency-Key que mandamos (solo en los POST).
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly Headers $headers,
        public readonly string $body,
        public readonly \stdClass $data,
        public readonly ?string $idempotencyKey,
    ) {
        $this->requestId = $headers->get('raya-request-id');
    }

    /**
     * var_dump muestra solo el status y el request id: el body puede traer nombres, CUIT y CBU.
     *
     * @return array{http_status: int, request_id: string|null}
     */
    public function __debugInfo(): array
    {
        return ['http_status' => $this->httpStatus, 'request_id' => $this->requestId];
    }
}
