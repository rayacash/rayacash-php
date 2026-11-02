<?php

declare(strict_types=1);

// La cobertura mínima de src/ (PHPUnit no trae un mínimo), sobre su reporte Cobertura:
//
//   php scripts/check-coverage.php build/coverage/cobertura.xml --lines=95 --branches=95 \
//       --functions=95
//
// - líneas: las líneas ejecutables de src/ que corrieron;
// - ramas: los bloques de código que corrieron (solo con Xdebug y --path-coverage);
// - funciones: las funciones y los métodos que corrieron al menos una vez, como en Node.
// Pedir --branches sin datos de ramas (con PCOV) es un error, no un 100 %.

$names = ['lines' => 'líneas', 'branches' => 'ramas', 'functions' => 'funciones'];
$file = $argv[1] ?? '';
$minimums = [];
$option = '/\A--(lines|branches|functions)=([0-9]+(?:\.[0-9]+)?)\z/';
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match($option, $argument, $m) !== 1) {
        fwrite(
            STDERR,
            "No entiendo «{$argument}»: usá --lines=95, --branches=95 o --functions=95.\n",
        );
        exit(2);
    }
    $minimums[$m[1]] = $m[2];
}

libxml_use_internal_errors(true);
$report = is_file($file) ? simplexml_load_file($file) : false;
if ($report === false) {
    fwrite(STDERR, "No hay un reporte Cobertura en «{$file}»: corré composer test:coverage.\n");
    exit(2);
}

// Las funciones: los <method> del reporte (los que no tienen líneas ejecutables no aparecen).
$methods = $report->xpath('//method') ?: [];
$ran = array_filter(
    $methods,
    static fn(SimpleXMLElement $method): bool => (float) (string) $method['line-rate'] > 0,
);
$count = static fn(string $name): int => (int) (string) $report[$name];
$totals = [
    'lines' => [$count('lines-covered'), $count('lines-valid')],
    'branches' => [$count('branches-covered'), $count('branches-valid')],
    'functions' => [count($ran), count($methods)],
];

$summary = [];
$failures = [];
foreach ($totals as $metric => [$covered, $valid]) {
    $percent = $valid > 0 ? 100 * $covered / $valid : null;
    $summary[] = $percent === null
        ? "{$names[$metric]}: sin datos"
        : sprintf('%s: %.2F %% (%d de %d)', $names[$metric], $percent, $covered, $valid);
    $minimum = $minimums[$metric] ?? null;
    if ($minimum === null) {
        continue;
    }
    if ($percent === null) {
        $failures[] = "No hay datos de {$names[$metric]}: medilas con Xdebug y --path-coverage.";
    } elseif ($covered * 100 < (float) $minimum * $valid) {
        $failures[] = sprintf(
            'La cobertura de %s es %.2F %%: el mínimo es %s %%.',
            $names[$metric],
            $percent,
            $minimum,
        );
    }
}

$line = 'Cobertura de src/: ' . implode(', ', $summary) . '.';
echo $line, "\n";
$stepSummary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($stepSummary) && $stepSummary !== '') {
    file_put_contents($stepSummary, $line . "\n", FILE_APPEND);
}
if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
