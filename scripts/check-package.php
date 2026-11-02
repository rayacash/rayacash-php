<?php

declare(strict_types=1);

// Lo corre scripts/check-package.sh dentro de un proyecto vacío que instaló el paquete con
// Composer: cada clase del SDK carga con el autoload de producción, RayaCash::VERSION es la del
// paquete y una request con un transporte falso funciona. Nunca sale a la red.
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

$version = $argv[1] ?? '';
check_package(RayaCash::VERSION === $version, "RayaCash::VERSION no es {$version}");

// Cada archivo de src/ define la clase que dice su ruta (PSR-4) y carga en este PHP.
$src = __DIR__ . '/vendor/rayacash/rayacash-php/src';
$classes = 0;
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
);
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
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

echo "rayacash/rayacash-php {$version}: se instala, carga sus {$classes} clases y funciona.\n";
