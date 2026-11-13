<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * La lectura del DNI (checks->document): frente y dorso.
 *
 * @property-read 'passed'|'failed'|'pending'|'unavailable'|'not_applicable' $status passed: salió
 *     bien. failed: no pasó (la verificación quedó declined). pending: todavía no se hizo o no
 *     terminó. unavailable: no pudimos hacerlo. not_applicable: este tipo de verificación no lo
 *     lleva. Pueden sumarse valores.
 */
final class DocumentCheck extends RayaObject {}
