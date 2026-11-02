<?php

declare(strict_types=1);

namespace RayaCash\Tests\Types;

use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los tipos de la API pública con PHPStan, como check_types.py en Python (mypy y pyright) y
 * types.test-d.ts en Node (tsc). Corre con `composer analyse` (phpunit --testsuite types).
 *
 * - data/*.php: un assertType() por cada tipo que ve quien integra (cada uno es un caso).
 * - data/expect-errors.php: lo que PHPStan tiene que rechazar, como los @ts-expect-error de Node.
 *   El resto de data/ tiene que pasar sin errores al nivel max, como check_types.py con mypy
 *   --strict.
 *
 * Los archivos de data/ nunca se ejecutan, y el análisis común de PHPStan los saltea
 * (phpstan.neon.dist): solo los lee este test.
 */
final class TypesTest extends TypeInferenceTestCase
{
    private const EXPECT_ERRORS = 'expect-errors.php';

    /** @return iterable<string, array<mixed>> */
    public static function assertions(): iterable
    {
        foreach (self::dataFiles() as $file) {
            if (basename($file) !== self::EXPECT_ERRORS) {
                yield from self::gatherAssertTypes($file);
            }
        }
    }

    #[DataProvider('assertions')]
    public function testType(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /**
     * PHPStan, como lo corre quien usa el SDK (nivel max, con phpstan.neon.dist), da en data/
     * exactamente los errores marcados en expect-errors.php: ni uno menos (un tipo que se aflojó)
     * ni uno más (un uso documentado que dejó de pasar).
     */
    public function testPhpstanReportsTheExpectedErrors(): void
    {
        $files = self::dataFiles();
        $expected = [];
        foreach ($files as $file) {
            foreach (self::markers($file) as $line => $errors) {
                $expected[basename($file) . ':' . $line] = $errors;
            }
        }
        self::assertNotSame([], $expected, 'expect-errors.php no marca ningún error.');

        $actual = self::analyse($files);
        $report = self::report($expected, $actual);
        self::assertSame(
            array_keys($expected),
            array_keys($actual),
            "Las líneas con errores no son las marcadas:\n{$report}",
        );
        foreach ($expected as $place => $errors) {
            $found = $actual[$place];
            self::assertCount(count($errors), $found, "{$place}:\n{$report}");
            foreach ($errors as $i => [$identifier, $fragment]) {
                self::assertSame($identifier, $found[$i][0], "{$place}:\n{$report}");
                self::assertStringContainsString($fragment, $found[$i][1], "{$place}:\n{$report}");
            }
        }
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [self::root() . '/phpstan.neon.dist'];
    }

    /** @return list<string> */
    private static function dataFiles(): array
    {
        $files = glob(__DIR__ . '/data/*.php') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Los errores que marca un archivo: cada «// error: identificador[: parte del mensaje]» vale
     * para la próxima línea que no es un comentario.
     *
     * @return array<int, list<array{string, string}>> Por línea, [identificador, fragmento].
     */
    private static function markers(string $file): array
    {
        $markers = [];
        $pending = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (preg_match('~\A\s*// error: ([a-zA-Z][\w.]*)(?:: (.+))?\z~', $line, $match) === 1) {
                $pending[] = [$match[1], $match[2] ?? ''];
            } elseif ($pending !== [] && preg_match('~\A\s*(//.*)?\z~', $line) !== 1) {
                $markers[$index + 1] = self::sorted($pending);
                $pending = [];
            }
        }
        self::assertSame([], $pending, "{$file}: un «// error:» sin línea de código abajo.");
        return $markers;
    }

    /**
     * Los errores de PHPStan sobre esos archivos, por «archivo:línea», como [identificador,
     * mensaje]. Corre el PHPStan de vendor/ en otro proceso, con la config del repo pero sin
     * excluir tests/Types/data, y su caché en un directorio temporal.
     *
     * @param list<string> $files
     * @return array<string, list<array{string, string}>>
     */
    private static function analyse(array $files): array
    {
        $tmp = sys_get_temp_dir() . '/rayacash-types-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tmp, 0o700, true), "No pude crear {$tmp}.");
        try {
            $config = "{$tmp}/phpstan.neon";
            file_put_contents($config, implode("\n", [
                'includes:',
                '    - ' . self::root() . '/phpstan.neon.dist',
                'parameters:',
                "    tmpDir: {$tmp}/cache",
                '    excludePaths!:',
                '        analyse: []',
                '        analyseAndScan: []',
                '',
            ]));
            $command = [
                PHP_BINARY,
                self::root() . '/vendor/bin/phpstan',
                'analyse',
                '--no-progress',
                '--memory-limit=1G',
                '--error-format=json',
                "--configuration={$config}",
                ...$files,
            ];
            $output = "{$tmp}/salida.json";
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', "{$tmp}/err", 'w']],
                $pipes,
                self::root(),
            );
            self::assertIsResource($process, 'No pude correr PHPStan.');
            fclose($pipes[0]);
            $status = proc_close($process);
            $stderr = (string) file_get_contents("{$tmp}/err");
            $json = json_decode((string) file_get_contents($output), true);
        } finally {
            self::remove($tmp);
        }
        // 0: sin errores; 1: con errores. Otro código es que PHPStan no pudo analizar.
        self::assertContains($status, [0, 1], "PHPStan falló ({$status}):\n{$stderr}");
        self::assertIsArray($json, "PHPStan no devolvió JSON:\n{$stderr}");
        self::assertSame([], $json['errors'] ?? null, 'PHPStan no pudo analizar todo.');
        $files = $json['files'] ?? [];
        self::assertIsArray($files);

        $errors = [];
        foreach ($files as $path => $result) {
            self::assertIsArray($result);
            self::assertIsArray($result['messages'] ?? null);
            foreach ($result['messages'] as $message) {
                self::assertIsArray($message);
                $line = $message['line'] ?? null;
                $identifier = $message['identifier'] ?? '(sin identificador)';
                $text = $message['message'] ?? null;
                self::assertIsInt($line);
                self::assertIsString($identifier);
                self::assertIsString($text);
                $errors[basename((string) $path) . ':' . $line][] = [$identifier, $text];
            }
        }
        ksort($errors, SORT_NATURAL);
        return array_map(self::sorted(...), $errors);
    }

    /**
     * Lo esperado y lo que dio PHPStan, para el mensaje de una falla.
     *
     * @param array<string, list<array{string, string}>> $expected
     * @param array<string, list<array{string, string}>> $actual
     */
    private static function report(array $expected, array $actual): string
    {
        $lines = ['Esperados:'];
        foreach ($expected as $place => $errors) {
            foreach ($errors as [$identifier, $fragment]) {
                $lines[] = "  {$place} {$identifier}: {$fragment}";
            }
        }
        $lines[] = 'Reportados:';
        foreach ($actual as $place => $errors) {
            foreach ($errors as [$identifier, $message]) {
                $lines[] = "  {$place} {$identifier}: {$message}";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<array{string, string}> $errors
     * @return list<array{string, string}>
     */
    private static function sorted(array $errors): array
    {
        usort($errors, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $errors;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove("{$path}/{$name}");
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
