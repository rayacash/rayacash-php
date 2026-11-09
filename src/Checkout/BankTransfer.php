<?php

declare(strict_types=1);

namespace RayaCash\Checkout;

use RayaCash\RayaObject;

/**
 * Los datos para pagar por transferencia: del pool de CVUs del comercio conectado.
 *
 * @property-read string $cvu El CVU al que hay que transferir: 22 dígitos.
 * @property-read string|null $alias El alias del CVU. Si es null, mostrá solo el CVU.
 */
final class BankTransfer extends RayaObject {}
