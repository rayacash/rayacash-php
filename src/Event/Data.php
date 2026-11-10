<?php

declare(strict_types=1);

namespace RayaCash\Event;

use RayaCash\ConnectOrder;
use RayaCash\Payment;
use RayaCash\RayaObject;
use RayaCash\Transfer;

/**
 * Lo que trae un evento: el Transfer, el Payment o el ConnectOrder tal como estaba cuando ocurrió.
 *
 * @property-read Transfer|Payment|ConnectOrder|RayaObject $object
 */
final class Data extends RayaObject {}
