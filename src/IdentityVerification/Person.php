<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * Los datos de la persona según el registro (person): solo cuando la verificación está verified,
 * y nunca en los eventos. En una biometric, los de la verificación de reference. Cualquier campo
 * puede venir en null. Son datos personales: no los loguees y guardá solo lo que necesitás.
 *
 * @property-read string|null $first_name Nombres, como figuran en el registro.
 * @property-read string|null $last_name Apellidos, como figuran en el registro.
 * @property-read string|null $document_type Hoy, siempre 'DNI'.
 * @property-read string|null $document_number El número de documento, sin puntos.
 * @property-read string|null $sex F, M o X.
 * @property-read string|null $birth_date YYYY-MM-DD.
 * @property-read string|null $nationality
 * @property-read string|null $cuil 11 dígitos, sin guiones.
 * @property-read Address $address El domicilio que figura en el registro.
 * @property-read IdentityDocument $document El ejemplar del DNI.
 */
final class Person extends RayaObject
{
    protected const INNER_CLASSES = [
        'address' => Address::class,
        'document' => IdentityDocument::class,
    ];
}
