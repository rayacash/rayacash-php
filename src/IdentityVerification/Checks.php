<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * Cada control de una verificación con su resultado. Los que todavía no se hicieron están en
 * pending; en una biometric, document y registry son not_applicable.
 *
 * @property-read DocumentCheck $document La lectura del DNI: frente y dorso.
 * @property-read LivenessCheck $liveness La prueba de vida: que haya una persona real frente a la
 *     cámara.
 * @property-read FaceMatchCheck $face_match La selfie contra la foto del DNI (document) o contra la
 *     verificación de reference (biometric).
 * @property-read RegistryCheck $registry La validación contra el Registro Nacional de las Personas
 *     (RENAPER).
 */
final class Checks extends RayaObject
{
    protected const INNER_CLASSES = [
        'document' => DocumentCheck::class,
        'liveness' => LivenessCheck::class,
        'face_match' => FaceMatchCheck::class,
        'registry' => RegistryCheck::class,
    ];
}
