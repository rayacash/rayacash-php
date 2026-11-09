<?php

declare(strict_types=1);

namespace RayaCash\Event;

use RayaCash\Checkout;
use RayaCash\Payment;
use RayaCash\RayaObject;
use RayaCash\Transfer;

/**
 * Lo que trae un evento: el Transfer, el Payment o el Checkout tal como estaba cuando ocurrió.
 *
 * @property-read Transfer|Payment|Checkout|RayaObject $object
 */
final class Data extends RayaObject {}
