<?php

declare(strict_types=1);

// 100 columnas, como rayacash-python (ruff) y rayacash-node (Biome). php-cs-fixer no corta
// líneas, así que lo chequea este script. Cuenta caracteres, no bytes: «ñ» y «…» valen uno.
//
//   php scripts/check-columns.php     (composer lint)

const LIMIT = 100;

$root = dirname(__DIR__);
$files = array_merge(glob("{$root}/*.php") ?: [], glob("{$root}/.*.php") ?: []);
foreach (['src', 'tests', 'examples', 'scripts'] as $folder) {
    if (!is_dir("{$root}/{$folder}")) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator("{$root}/{$folder}", FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$errors = [];
foreach ($files as $path) {
    $relative = substr($path, strlen($root) + 1);
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
        $columns = preg_match_all('/./su', $line);
        $where = $relative . ':' . ($index + 1);
        if ($columns === false) {
            $errors[] = "{$where}: no es UTF-8 válido.";
        } elseif ($columns > LIMIT) {
            $errors[] = "{$where}: {$columns} columnas (el máximo es " . LIMIT . ').';
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo 'Las ' . count($files) . ' fuentes PHP entran en ' . LIMIT . " columnas.\n";
