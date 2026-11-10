<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\Deprecation;

// Checkout es el nombre de ConnectOrder en la 1.1.0: un alias deprecado de la misma clase, hasta
// la 2.0.0. Este archivo carga solo si tu código nombra Checkout antes que ConnectOrder: avisa y
// carga ConnectOrder, que registra el alias (ver Internal\Deprecation).
Deprecation::alias(Checkout::class, ConnectOrder::class);

if (\false) {
    /**
     * Nunca se declara: el nombre es un alias de ConnectOrder. Para tu IDE y PHPStan, que no ven
     * el alias, describe la API de la 1.1.0: lo que devuelve con este nombre (como
     * `$client->checkout->orders`) es un Checkout, con Checkout\Item… adentro, así tu código de
     * la 1.1.0 con tipos sigue pasando el análisis.
     *
     * @deprecated Desde la 1.2.0: usá ConnectOrder, que es la misma clase. Se elimina en la 2.0.0.
     *
     * @property-read list<Checkout\Item> $items
     * @property-read Checkout\BankTransfer $bank_transfer
     * @property-read Checkout\BackUrls $back_urls
     * @property-read Checkout\Payer|null $payer
     */
    final class Checkout extends ConnectOrder
    {
        // Sin phpdoc: PHPStan toma el de ConnectOrder (los tipos de params y options).

        public function refund(array $options = []): Checkout {}

        public static function create(array $params, array $options = []): Checkout {}

        public static function retrieve(string $orderId, array $options = []): Checkout {}

        public static function refundById(string $orderId, array $options = []): Checkout {}
    }
}
