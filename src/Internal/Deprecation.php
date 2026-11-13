<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Los nombres de la 1.1.0 que Raya Connect cambió en la 1.2.0 (Checkout →
 * ConnectOrder, Checkout\Item → ConnectOrder\Item, CheckoutService → ConnectService…): siguen
 * como alias deprecados de las clases nuevas, la misma clase con otro nombre, hasta la 2.0.0.
 *
 * - Cada clase nueva registra su alias al final de su archivo, apenas carga: así
 *   `instanceof Checkout` y un parámetro tipado Checkout andan aunque tu código nunca haya
 *   nombrado Checkout antes (PHP no carga clases para instanceof ni para comparar tipos).
 * - src/aliases.php, que el autoload de Composer carga en cada request (autoload.files),
 *   registra el de las clases nuevas que ya están declaradas sin haber corrido el final de su
 *   archivo: las que opcache.preload precargó con opcache_compile_file().
 * - Cada nombre viejo tiene su archivo PSR-4 (src/Checkout.php…), que carga solo si tu código lo
 *   nombra antes que el nuevo: llama a alias(), que avisa y carga la clase nueva.
 * - La declaración del `if (\false)` de esos archivos nunca corre: está para Composer (sin ella,
 *   el classmap de --classmap-authoritative no tiene el nombre viejo), tu IDE y PHPStan, y
 *   describe la API de la 1.1.0 (lo que devuelven los nombres viejos es un Checkout…).
 */
final class Deprecation
{
    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * Lo que hace el archivo de un nombre viejo al cargar: avisa una vez con un E_USER_DEPRECATED
     * y carga la clase nueva, que registra el alias. Si la nueva ya estaba cargada sin registrarlo
     * (opcache.preload con opcache_compile_file() la compila sin correr el final de su archivo) y
     * nadie lo registró (sin el autoload de Composer, que carga src/aliases.php), lo registra acá.
     *
     * El aviso va silenciado con @, como los de Symfony: lo ven su profiler, el log de
     * deprecaciones de Laravel y tu set_error_handler(), pero PHP no lo muestra ni lo loguea por
     * su cuenta (en desarrollo, display_errors lo escribiría en medio de tu HTML o tu JSON).
     *
     * @param class-string $new
     */
    public static function alias(string $old, string $new): void
    {
        @\trigger_error(
            "La clase {$old} está deprecada desde la 1.2.0: usá {$new}, que es la misma clase. Se "
                . 'elimina en la 2.0.0.',
            \E_USER_DEPRECATED,
        );
        \class_exists($new);
        if (!\class_exists($old, false)) {
            \class_alias($new, $old);
        }
    }
}
