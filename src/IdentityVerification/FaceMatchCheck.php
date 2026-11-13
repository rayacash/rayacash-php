<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * La selfie de la prueba de vida (checks->face_match) contra la foto del DNI (document) o contra
 * la selfie de la verificación de reference (biometric).
 *
 * @property-read 'passed'|'failed'|'pending'|'unavailable'|'not_applicable' $status Como el de
 *     DocumentCheck. Pueden sumarse valores.
 * @property-read int|float|null $similarity Parecido entre las dos caras, de 0 a 100; null hasta
 *     que el control termina.
 */
final class FaceMatchCheck extends RayaObject {}
