<?php

declare(strict_types=1);

namespace RayaCash\Event;

use RayaCash\Payment;
use RayaCash\RayaObject;
use RayaCash\Transfer;

/**
 * Lo que trae un evento: el Transfer o el Payment tal como estaba cuando ocurrió.
 *
 * @property-read Transfer|Payment|RayaObject $object
 */
final class Data extends RayaObject {}
