<?php

declare(strict_types=1);

// phpunit.xml.dist tiene que valer para el PHPUnit de cada versión de PHP. PHPUnit solo avisa si
// el XML no pasa su XSD; acá es un error. También comprueba que Composer haya instalado el major
// que corresponde: si una dependencia lo frenara, el CI probaría menos de lo que dice.
//
//   php scripts/check-phpunit-config.php      (CI en cada versión y scripts/dev.sh matrix)

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$version = PHPUnit\Runner\Version::id();
// Cuando composer.json sume ^14, agregá su piso de PHP acá.
$expected = match (true) {
    PHP_VERSION_ID < 80200 => 10,
    PHP_VERSION_ID < 80300 => 11,
    PHP_VERSION_ID < 80400 => 12,
    default => 13,
};
$errors = [];
if ((int) $version !== $expected) {
    $errors[] = sprintf(
        'PHP %s tendría que usar PHPUnit %d, no %s: corré composer why-not phpunit/phpunit %d.',
        PHP_VERSION,
        $expected,
        $version,
        $expected,
    );
}

libxml_use_internal_errors(true);
$xml = new DOMDocument();
$valid = $xml->load("{$root}/phpunit.xml.dist")
    && $xml->schemaValidate("{$root}/vendor/phpunit/phpunit/phpunit.xsd");
if (!$valid) {
    foreach (libxml_get_errors() as $error) {
        $errors[] = sprintf('phpunit.xml.dist:%d: %s', $error->line, trim($error->message));
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "phpunit.xml.dist vale para PHPUnit {$version} (PHP " . PHP_VERSION . ").\n";
