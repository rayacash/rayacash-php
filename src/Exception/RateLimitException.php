<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 429: demasiadas requests. getRetryAfter() dice cuántos segundos esperar. */
class RateLimitException extends RayaException {}
