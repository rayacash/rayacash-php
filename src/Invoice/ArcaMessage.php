<?php

declare(strict_types=1);

namespace RayaCash\Invoice;

use RayaCash\RayaObject;

/**
 * Un error o una observación de ARCA sobre una factura, tal como lo mandó ARCA.
 *
 * @property-read 'error'|'observation' $type error: ARCA rechazó el pedido entero (la factura no
 *     se emitió). observation: una observación sobre el comprobante, que viene con una rechazada
 *     (el motivo) o con una emitida (un aviso). Pueden sumarse valores.
 * @property-read int $code El código de ARCA.
 * @property-read string $message El texto de ARCA, tal cual (hasta 500 caracteres).
 */
final class ArcaMessage extends RayaObject
{
    public const TYPE_ERROR = 'error';
    public const TYPE_OBSERVATION = 'observation';
}
