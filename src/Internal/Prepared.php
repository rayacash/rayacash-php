<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\HttpClient\Request;

/**
 * @internal Una request lista para mandar: la misma en cada reintento (con la misma
 * Idempotency-Key).
 */
final class Prepared
{
    /** @param array<string, string> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly Request $request,
        public readonly int $maxRetries,
        public readonly ?string $idempotencyKey,
    ) {}
}
