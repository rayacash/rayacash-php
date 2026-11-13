<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * El domicilio de la persona según el registro (person->address). Cada campo puede ser null.
 *
 * @property-read string|null $street Calle.
 * @property-read string|null $number Altura.
 * @property-read string|null $floor Piso.
 * @property-read string|null $apartment Departamento.
 * @property-read string|null $city Localidad.
 * @property-read string|null $municipality Partido o departamento.
 * @property-read string|null $province Provincia.
 * @property-read string|null $postal_code Código postal.
 * @property-read string|null $country País.
 */
final class Address extends RayaObject {}
