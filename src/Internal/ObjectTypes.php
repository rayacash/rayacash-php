<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\Balance;
use RayaCash\ConnectOrder;
use RayaCash\ConnectPayout;
use RayaCash\Event;
use RayaCash\IdentityVerification;
use RayaCash\IdentityVerificationFiles;
use RayaCash\ListObject;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\RayaObject;
use RayaCash\Transfer;
use RayaCash\User;

/**
 * @internal Qué clase le toca a cada valor de "object", entero (con su punto, como
 * 'connect.order'). Lo que no está acá queda como RayaObject: la API puede sumar tipos y tu código
 * tiene que ignorarlos.
 */
final class ObjectTypes
{
    private const CLASSES = [
        'balance' => Balance::class,
        // El nombre de la 1.1.0: la API ya no lo manda, pero lo trae un evento de antes del
        // cambio si se reenvía.
        'checkout' => ConnectOrder::class,
        'connect.order' => ConnectOrder::class,
        'connect.payout' => ConnectPayout::class,
        'event' => Event::class,
        'identity.verification' => IdentityVerification::class,
        'identity.verification_files' => IdentityVerificationFiles::class,
        'list' => ListObject::class,
        'oauth_token' => OAuthToken::class,
        'payment' => Payment::class,
        'transfer' => Transfer::class,
        'user' => User::class,
    ];

    // Para el error de un endpoint que respondió otra cosa («…no es una lista»).
    private const NOUNS = [
        Balance::class => 'un saldo',
        ConnectOrder::class => 'un pago de Connect',
        ConnectPayout::class => 'un payout',
        IdentityVerification::class => 'una verificación',
        IdentityVerificationFiles::class => 'el objeto de las imágenes de una verificación',
        ListObject::class => 'una lista',
        OAuthToken::class => 'un access_token',
        Payment::class => 'un pago',
        Transfer::class => 'una transferencia',
        User::class => 'un usuario',
    ];

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** @return class-string<RayaObject>|null */
    public static function get(mixed $objectName): ?string
    {
        return is_string($objectName) ? self::CLASSES[$objectName] ?? null : null;
    }

    public static function noun(string $class): string
    {
        return self::NOUNS[$class] ?? 'un ' . Text::shortName($class);
    }
}
