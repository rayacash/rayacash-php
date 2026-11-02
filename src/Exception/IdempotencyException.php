<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 409 IDEMPOTENCY_KEY_REUSED o IDEMPOTENCY_KEY_IN_USE. */
class IdempotencyException extends ConflictException {}
