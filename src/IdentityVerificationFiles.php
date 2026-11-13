<?php

declare(strict_types=1);

namespace RayaCash;

/**
 * Las imágenes de una verificación de identidad
 * (`$client->identity->verifications->files($verificationId)`): el frente y el dorso del DNI y la
 * selfie de la prueba de vida, cada una con una URL firmada que vence a los 5 minutos.
 *
 * Bajá cada imagen enseguida, con tu propio cliente HTTP, y no guardes las URLs: pedí otras
 * cuando las necesites. La que esa verificación no tiene (front y back en una biometric, o todas
 * si ya no las guardamos) viene en null. Son datos personales: guardá solo lo que necesitás.
 *
 * @property-read 'identity.verification_files' $object
 * @property-read IdentityVerificationFiles\File|null $front El frente del DNI.
 * @property-read IdentityVerificationFiles\File|null $back El dorso del DNI.
 * @property-read IdentityVerificationFiles\File|null $selfie La selfie de la prueba de vida.
 */
final class IdentityVerificationFiles extends RayaObject
{
    public const OBJECT_NAME = 'identity.verification_files';

    protected const INNER_CLASSES = [
        'front' => IdentityVerificationFiles\File::class,
        'back' => IdentityVerificationFiles\File::class,
        'selfie' => IdentityVerificationFiles\File::class,
    ];
}
