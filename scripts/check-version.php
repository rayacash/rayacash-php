<?php

declare(strict_types=1);

// La versión vive en un solo lugar: RayaCash::VERSION (la usa el User-Agent). composer.json no la
// lleva: Packagist la toma del tag. Este script comprueba que la primera sección del CHANGELOG y
// la línea «Versión X.Y.Z» del README digan lo mismo y, con un tag, que sea vX.Y.Z y que ya nada
// diga «sin publicar».
//
//   php scripts/check-version.php            CI (Paquete y versión) y scripts/dev.sh package
//   php scripts/check-version.php v1.2.0     release.yml, con el tag del release
//   php scripts/check-version.php --print    solo la versión (la usa scripts/check-package.sh)

const VERSION_FILE = 'src/RayaCash.php';

function version_fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Las capturas de cada línea de $file (del repo) que cumple $pattern, en orden.
 *
 * @return list<array<string>>
 */
function version_lines(string $file, string $pattern): array
{
    $path = dirname(__DIR__) . '/' . $file;
    $text = is_file($path) ? file_get_contents($path) : false;
    if ($text === false) {
        version_fail("No encontré {$file}.");
    }
    $found = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (preg_match($pattern, $line, $match) === 1) {
            $found[] = $match;
        }
    }
    return $found;
}

$constants = version_lines(
    VERSION_FILE,
    "/\A\s*public const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';\z/",
);
if (count($constants) !== 1) {
    version_fail(
        "No encontré una sola línea «public const VERSION = 'X.Y.Z';» en " . VERSION_FILE . '.',
    );
}
$version = $constants[0][1];
if (($argv[1] ?? null) === '--print') {
    echo $version, "\n";
    exit(0);
}

$headings = version_lines('CHANGELOG.md', '/\A## (\S+)(.*)\z/');
if (($headings[0][1] ?? null) !== $version) {
    version_fail("La primera sección del CHANGELOG tiene que ser «## {$version}».");
}
$readme = version_lines('README.md', '/\AVersión ([0-9]+\.[0-9]+\.[0-9]+)(.*)\z/');
if (count($readme) !== 1 || $readme[0][1] !== $version) {
    version_fail(
        "El README tiene que tener una sola línea «Versión {$version}. …»: es la página del "
        . 'paquete en Packagist.',
    );
}

$tag = $argv[1] ?? null;
if ($tag !== null) {
    if ($tag !== "v{$version}") {
        version_fail("El tag {$tag} no coincide con la versión {$version} de " . VERSION_FILE
            . ": el release tiene que ser v{$version}.");
    }
    if (str_contains($headings[0][2] . $readme[0][2], 'sin publicar')) {
        version_fail('El CHANGELOG o el README todavía dicen «sin publicar».');
    }
}

$line = "Versión {$version}: el release es v{$version}, sobre este commit.";
echo getenv('GITHUB_ACTIONS') === 'true' ? "::notice::{$line}\n" : "{$line}\n";
