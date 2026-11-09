<?php

declare(strict_types=1);

namespace RayaCash\User;

use RayaCash\RayaObject;

/**
 * El domicilio que figura en el documento, según RENAPER. Cada campo puede ser null.
 *
 * @property-read string|null $street
 * @property-read string|null $number
 * @property-read string|null $floor
 * @property-read string|null $apartment
 * @property-read string|null $city
 * @property-read string|null $province
 * @property-read string|null $postal_code
 * @property-read string|null $country
 */
final class Address extends RayaObject {}
