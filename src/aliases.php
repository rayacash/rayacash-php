<?php

declare(strict_types=1);

namespace RayaCash;

// Los alias deprecados de los nombres de la 1.1.0 (ver Internal\Deprecation) para las clases
// nuevas que ya están declaradas cuando arranca el autoload de Composer, que carga este archivo
// en cada request (autoload.files de composer.json). Pasa con opcache.preload: un script de
// precarga que compila src/ con opcache_compile_file() declara las clases nuevas sin correr el
// final de su archivo, el class_alias() del nombre viejo, y sin esto `instanceof Checkout` o un
// parámetro tipado Checkout fallarían. Las demás clases registran su alias al cargar, como
// siempre. Acá no se carga ninguna clase (class_exists() sin autoload) ni se avisa nada.
(static function (): void {
    $aliases = [
        ConnectOrder::class => Checkout::class,
        ConnectOrder\BackUrls::class => Checkout\BackUrls::class,
        ConnectOrder\BankTransfer::class => Checkout\BankTransfer::class,
        ConnectOrder\Item::class => Checkout\Item::class,
        ConnectOrder\Payer::class => Checkout\Payer::class,
        Service\ConnectOrderService::class => Service\CheckoutOrderService::class,
        Service\ConnectService::class => Service\CheckoutService::class,
    ];
    foreach ($aliases as $new => $old) {
        if (\class_exists($new, false) && !\class_exists($old, false)) {
            \class_alias($new, $old);
        }
    }
})();
