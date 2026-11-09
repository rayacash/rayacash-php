<?php

declare(strict_types=1);

namespace RayaCash\User;

use RayaCash\RayaObject;

/**
 * Los datos de la empresa (solo si el type de la cuenta es business).
 *
 * @property-read string|null $legal_name La razón social.
 * @property-read string|null $tax_id CUIT de la empresa: 11 dígitos, sin guiones.
 * @property-read string|null $company_type El tipo societario (S.A., S.R.L.…).
 * @property-read Representative|null $representative Quien representa a la empresa ante Raya.
 */
final class Business extends RayaObject
{
    protected const INNER_CLASSES = ['representative' => Representative::class];
}
