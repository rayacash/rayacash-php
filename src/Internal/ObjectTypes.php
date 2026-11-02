<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\Balance;
use RayaCash\Event;
use RayaCash\ListObject;
use RayaCash\Payment;
use RayaCash\RayaObject;
use RayaCash\Transfer;

/**
 * @internal Qué clase le toca a cada valor de "object". Lo que no está acá queda como
 * RayaObject: la API puede sumar tipos y tu código tiene que ignorarlos.
 */
final class ObjectTypes
{
    private const CLASSES = [
        'balance' => Balance::class,
        'event' => Event::class,
        'list' => ListObject::class,
        'payment' => Payment::class,
        'transfer' => Transfer::class,
    ];

    // Para el error de un endpoint que respondió otra cosa («…no es una lista»).
    private const NOUNS = [
        Balance::class => 'un saldo',
        ListObject::class => 'una lista',
        Payment::class => 'un pago',
        Transfer::class => 'una transferencia',
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
