<?php

declare(strict_types=1);

namespace RayaCash\User;

use RayaCash\RayaObject;

/**
 * Quien representa a la empresa ante Raya (business->representative).
 *
 * @property-read string|null $first_name
 * @property-read string|null $last_name
 * @property-read string|null $tax_id CUIL del representante: 11 dígitos, sin guiones.
 */
final class Representative extends RayaObject {}
