<?php

declare(strict_types=1);

namespace RayaCash\PspCustomer;

use RayaCash\RayaObject;

/**
 * El CVU de un cliente de Raya PSP, a su nombre: el activo o, si no tiene uno, el último que tuvo.
 *
 * @property-read string $account El CVU: 22 dígitos.
 * @property-read string|null $alias Su alias: null mientras lo asignamos.
 * @property-read string $holder_name El titular con el que figura el CVU en la red de
 *     transferencias: en mayúsculas, sin acentos y hasta 40 caracteres.
 * @property-read 'active'|'closing'|'closed' $status active: recibe y envía. closing: lo dimos de
 *     baja y el banco todavía la está procesando. closed: dado de baja. Pueden sumarse valores.
 * @property-read string $created_at Cuándo quedó activo (ISO 8601 en UTC).
 * @property-read string|null $closed_at Cuándo se dio de baja; null si no se dio de baja.
 */
final class Cvu extends RayaObject
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSING = 'closing';
    public const STATUS_CLOSED = 'closed';
}
