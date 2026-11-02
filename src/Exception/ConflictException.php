<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 409: choca con el estado del recurso (por ejemplo, PAYMENT_NOT_REFUNDABLE). */
class ConflictException extends RayaException {}
