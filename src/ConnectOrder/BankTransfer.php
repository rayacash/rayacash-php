<?php

declare(strict_types=1);

namespace RayaCash\ConnectOrder;

use RayaCash\RayaObject;

/**
 * Los datos para pagar por transferencia: del pool de CVUs del comercio conectado.
 *
 * @property-read string $cvu El CVU al que hay que transferir: 22 dígitos.
 * @property-read string|null $alias El alias del CVU. Si es null, mostrá solo el CVU.
 */
final class BankTransfer extends RayaObject {}

// Checkout\BankTransfer, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la 2.0.0
// (ver Internal\Deprecation).
\class_alias(BankTransfer::class, \RayaCash\Checkout\BankTransfer::class);
