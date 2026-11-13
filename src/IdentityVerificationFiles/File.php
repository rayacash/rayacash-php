<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerificationFiles;

use RayaCash\RayaObject;

/**
 * Una imagen de una verificación (front, back o selfie): una URL firmada que vence a los 5
 * minutos. Bajala enseguida, con tu propio cliente HTTP, y no guardes la URL.
 *
 * @property-read string $url La URL firmada de la imagen (JPEG).
 * @property-read string $expires_at Hasta cuándo sirve la URL (ISO 8601 en UTC).
 */
final class File extends RayaObject {}
