<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * El ejemplar del DNI de la persona (person->document). Cada campo puede ser null.
 *
 * @property-read string|null $copy El ejemplar, como 'A'.
 * @property-read string|null $issue_date Fecha de emisión, YYYY-MM-DD.
 * @property-read string|null $expiry_date Fecha de vencimiento, YYYY-MM-DD.
 */
final class IdentityDocument extends RayaObject {}
