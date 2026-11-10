<?php

declare(strict_types=1);

// Lo corre scripts/check-package.sh dentro de un proyecto vacío que instaló el paquete con
// Composer: el autoload de producción carga src/aliases.php sin cargar ninguna clase, cada clase
// del SDK (y cada nombre deprecado de la 1.1.0) carga con él, RayaCash::VERSION es la del paquete
// y una request con un transporte falso funciona. Nunca sale a la red.
//
//   php check-package.php 1.0.0

use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\HttpClient\Request;
use RayaCash\HttpClient\Response;
use RayaCash\RayaCash;
use RayaCash\RayaClient;

require __DIR__ . '/vendor/autoload.php';

// La de tests/Support/Fixtures.php, partida en dos para que ningún escáner de secretos la tome
// por una clave real.
const KEY = 'raya_sk_live_' . '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKL';

function check_package(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "El paquete instalado falla: {$what}.\n");
        exit(1);
    }
}

/**
 * Los mensajes de los errores que dispara $fn. Los E_USER_DEPRECATED no se muestran; el resto,
 * sí.
 *
 * @return list<string>
 */
function package_warnings(callable $fn): array
{
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return $level === E_USER_DEPRECATED;
    });
    try {
        $fn();
    } finally {
        restore_error_handler();
    }
    return $warnings;
}

$version = $argv[1] ?? '';

// Lo primero, antes de nombrar una clase: el require de arriba cargó src/aliases.php
// (autoload.files), que registra los alias de las clases que precargó opcache.preload, y ninguna
// clase del SDK.
$package = __DIR__ . '/vendor/rayacash/rayacash-php';
$manifest = json_decode((string) file_get_contents("{$package}/composer.json"), true);
$autoload = is_array($manifest) ? $manifest['autoload'] ?? null : null;
check_package(
    is_array($autoload) && ($autoload['files'] ?? null) === ['src/aliases.php'],
    'composer.json no tiene src/aliases.php en autoload.files',
);
$aliasFile = realpath("{$package}/src/aliases.php");
check_package(
    in_array($aliasFile, get_included_files(), true),
    'el autoload de Composer no cargó src/aliases.php',
);
$loaded = array_filter(
    get_declared_classes(),
    static fn(string $class): bool => str_starts_with($class, 'RayaCash\\'),
);
check_package($loaded === [], 'el autoload de Composer carga clases del SDK al arrancar');

check_package(RayaCash::VERSION === $version, "RayaCash::VERSION no es {$version}");

// Los nombres de la 1.1.0, alias deprecados hasta la 2.0.0, antes que las clases nuevas: cargan
// también con este classmap autoritativo (por la declaración del if (\false) de su archivo),
// avisan una vez y son la clase nueva.
$aliases = [
    \RayaCash\Checkout::class => \RayaCash\ConnectOrder::class,
    \RayaCash\Checkout\BackUrls::class => \RayaCash\ConnectOrder\BackUrls::class,
    \RayaCash\Checkout\BankTransfer::class => \RayaCash\ConnectOrder\BankTransfer::class,
    \RayaCash\Checkout\Item::class => \RayaCash\ConnectOrder\Item::class,
    \RayaCash\Checkout\Payer::class => \RayaCash\ConnectOrder\Payer::class,
    \RayaCash\Service\CheckoutOrderService::class => \RayaCash\Service\ConnectOrderService::class,
    \RayaCash\Service\CheckoutService::class => \RayaCash\Service\ConnectService::class,
];
foreach ($aliases as $old => $new) {
    $warnings = package_warnings(static function () use ($old): void {
        check_package(class_exists($old), "{$old} no carga con el autoload de Composer");
    });
    check_package((new ReflectionClass($old))->getName() === $new, "{$old} no es {$new}");
    check_package(
        count($warnings) === 1
            && str_starts_with($warnings[0], "La clase {$old} está deprecada"),
        "{$old} no avisó una vez que está deprecada",
    );
}

// Cada archivo de src/ define la clase que dice su ruta (PSR-4) y carga en este PHP, salvo
// src/aliases.php (arriba).
$src = "{$package}/src";
$classes = 0;
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
);
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    if ($file->getRealPath() === $aliasFile) {
        continue;
    }
    $class = 'RayaCash\\' . strtr(substr($file->getPathname(), strlen($src) + 1, -4), '/', '\\');
    check_package(
        class_exists($class) || interface_exists($class) || trait_exists($class)
            || enum_exists($class),
        "{$class} no carga con el autoload de Composer",
    );
    $classes++;
}

$fake = new class implements HttpClientInterface {
    public function send(Request $request): Response
    {
        check_package($request->method === 'GET', 'el método');
        check_package($request->url === 'https://api.raya.cash/v1/balance', 'la URL');
        check_package($request->header('Authorization') === 'Bearer ' . KEY, 'el Authorization');
        $userAgent = '/\Arayacash-php\/' . preg_quote(RayaCash::VERSION, '/')
            . ' PHP\/[0-9]+\.[0-9]+\.[0-9]+ curl\/\S+\z/';
        check_package(
            preg_match($userAgent, $request->header('User-Agent') ?? '') === 1,
            'el User-Agent',
        );
        return new Response(
            200,
            ['Content-Type' => 'application/json', 'Raya-Request-Id' => 'req_check'],
            '{"object":"balance","available":1500,"currency":"ARS"}',
        );
    }
};
$balance = (new RayaClient(KEY, ['http_client' => $fake]))->balance->retrieve();
check_package($balance->available === 1500 && $balance->currency === 'ARS', 'el Balance');
check_package($balance->getLastResponse()?->requestId === 'req_check', 'el request id');

echo "rayacash/rayacash-php {$version}: se instala, carga sus {$classes} clases (con los "
    . count($aliases) . " nombres deprecados de la 1.1.0) y funciona.\n";
