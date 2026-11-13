<?php

declare(strict_types=1);

namespace RayaCash\Event;

use RayaCash\ConnectOrder;
use RayaCash\IdentityVerification;
use RayaCash\Payment;
use RayaCash\RayaObject;
use RayaCash\Transfer;

/**
 * Lo que trae un evento: el Transfer, el Payment, el ConnectOrder o la IdentityVerification
 * (siempre con person en null) tal como estaba cuando ocurrió.
 *
 * @property-read Transfer|Payment|ConnectOrder|IdentityVerification|RayaObject $object
 */
final class Data extends RayaObject {}
