<?php

declare(strict_types=1);

namespace RayaCash\User;

use RayaCash\RayaObject;

/**
 * El documento que identifica a la cuenta.
 *
 * @property-read 'DNI'|'CUIT' $type DNI en una persona; CUIT en una empresa. El CUIL de una
 *     persona va en tax_id.
 * @property-read string $number Solo dígitos.
 */
final class Identification extends RayaObject {}
