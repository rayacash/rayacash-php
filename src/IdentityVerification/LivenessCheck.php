<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * La prueba de vida (checks->liveness): que haya una persona real frente a la cámara.
 *
 * @property-read 'passed'|'failed'|'pending'|'unavailable'|'not_applicable' $status Como el de
 *     DocumentCheck. Pueden sumarse valores.
 * @property-read int|float|null $confidence Qué tan segura es la prueba, de 0 a 100; null hasta
 *     que el control termina.
 */
final class LivenessCheck extends RayaObject {}
