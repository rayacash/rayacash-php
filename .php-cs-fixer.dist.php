<?php

declare(strict_types=1);

// Formato: PER-CS (el estándar de PHP-FIG), strict_types en todos los archivos y comillas
// simples. `composer fix` lo aplica y `composer lint` lo exige, junto con las 100 columnas de
// scripts/check-columns.php (php-cs-fixer no corta líneas). Un override local va en
// .php-cs-fixer.php (ignorado por git).

$finder = (new PhpCsFixer\Finder())
    ->in(array_filter(
        [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/scripts'],
        'is_dir',
    ))
    // Sin strict_types a propósito: llama al SDK como lo hace un proyecto sin strict_types.
    ->notPath('Support/Loose.php')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true) // declare_strict_types es «risky»
    ->setRules([
        '@PER-CS' => true,
        '@PHP8x1Migration' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_quote' => true,
    ])
    ->setFinder($finder);
