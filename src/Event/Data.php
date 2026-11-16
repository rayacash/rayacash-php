<?php

declare(strict_types=1);

namespace RayaCash\Event;

use RayaCash\ConnectOrder;
use RayaCash\IdentityVerification;
use RayaCash\Payment;
use RayaCash\PspCustomer;
use RayaCash\PspDocumentRequest;
use RayaCash\PspTransfer;
use RayaCash\RayaObject;
use RayaCash\Transfer;

/**
 * Lo que trae un evento: el Transfer, el Payment, el ConnectOrder, la IdentityVerification
 * (siempre con person en null), el PspCustomer, la PspTransfer o el PspDocumentRequest tal como
 * estaba cuando ocurrió.
 *
 * @property-read (
 *     Transfer|Payment|ConnectOrder|IdentityVerification|PspCustomer|PspTransfer
 *     |PspDocumentRequest|RayaObject
 * ) $object
 */
final class Data extends RayaObject {}
