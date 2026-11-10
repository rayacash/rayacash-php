<?php

declare(strict_types=1);

namespace RayaCash\Checkout;

use RayaCash\ConnectOrder;
use RayaCash\Internal\Deprecation;

// El nombre de ConnectOrder\BankTransfer en la 1.1.0: un alias deprecado de la misma clase,
// hasta la 2.0.0 (ver Internal\Deprecation).
Deprecation::alias(BankTransfer::class, ConnectOrder\BankTransfer::class);

if (\false) {
    /**
     * Nunca se declara: el nombre es un alias de ConnectOrder\BankTransfer.
     *
     * @deprecated Desde la 1.2.0: usá ConnectOrder\BankTransfer, que es la misma clase. Se
     *     elimina en la 2.0.0.
     */
    final class BankTransfer extends ConnectOrder\BankTransfer {}
}
