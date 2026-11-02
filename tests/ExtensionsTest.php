<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use RayaCash\Tests\Support\TestCase;

/**
 * El SDK pide solo PHP 8.1+, ext-curl y ext-json (un hosting compartido puede no tener nada
 * más): ni mbstring, ni ctype, ni iconv, ni intl, ni bcmath. Lo prueba leyendo los tokens de
 * src/: cada función que llama y cada clase que nombra tiene que ser de PHP o de esas dos.
 */
final class ExtensionsTest extends TestCase
{
    /** Lo que trae todo PHP 8.1+ y lo que pide composer.json (getExtensionName() en minúscula). */
    private const ALLOWED = [
        'core',
        'standard',
        'date',
        'pcre',
        'spl',
        'reflection',
        'hash',
        'random',
        'json',
        'curl',
    ];

    public function testComposerRequiresOnlyPhpCurlAndJson(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($composer);
        self::assertSame(
            ['php' => '>=8.1', 'ext-curl' => '*', 'ext-json' => '*'],
            $composer['require'] ?? null,
        );
    }

    public function testTheSdkOnlyCallsFunctionsFromPhpCurlAndJson(): void
    {
        $functions = self::used('function');
        // El escáner ve lo que usa el SDK: si no, este test pasaría sin mirar nada.
        foreach (['curl_exec', 'json_decode', 'preg_match', 'hash_hmac', 'is_string'] as $known) {
            self::assertArrayHasKey($known, $functions);
        }
        $wrong = [];
        foreach ($functions as $name => $places) {
            $problem = self::functionProblem($name);
            if ($problem !== null) {
                $wrong[] = "{$name}() ({$problem}): " . implode(', ', $places);
            }
        }
        self::assertSame([], $wrong, 'src/ solo puede llamar a funciones de PHP, curl y json.');
    }

    public function testTheSdkOnlyUsesClassesFromPhpCurlAndJson(): void
    {
        $classes = self::used('class');
        foreach (['CurlHandle', 'JsonException', 'TypeError', 'RayaCash\RayaObject'] as $known) {
            self::assertArrayHasKey($known, $classes);
        }
        $wrong = [];
        foreach ($classes as $name => $places) {
            $problem = self::classProblem($name);
            if ($problem !== null) {
                $wrong[] = "{$name} ({$problem}): " . implode(', ', $places);
            }
        }
        self::assertSame([], $wrong, 'src/ solo puede usar clases de PHP, curl y json.');
    }

    /** El escáner mismo: encuentra lo de otras extensiones y saltea métodos y declaraciones. */
    public function testTheScannerFindsWhatOtherExtensionsProvide(): void
    {
        $code = <<<'PHP'
            <?php

            namespace RayaCash\Internal;

            use IntlChar;
            use RayaCash\Internal\Text;

            final class Ejemplo
            {
                #[\SensitiveParameter]
                public function a(#[\SensitiveParameter] string $s): int
                {
                    return mb_strlen($s) + (\ctype_digit($s) ? 1 : 0) + $this->b() + self::c(...)();
                }

                public function &b(): int
                {
                    return strlen(iconv('UTF-8', 'ASCII', "x{$this->d}"));
                }

                public static function c(): string
                {
                    return bcadd('1', '2') . array_map('mb_strtoupper', ['x'])[0]
                        . \Normalizer::normalize('x') . new \NumberFormatter('es', 1) . Text::class;
                }
            }
            PHP;
        $found = self::uses($code);
        self::assertSame(
            [
                ['class', 'IntlChar'],
                ['class', 'RayaCash\Internal\Text'],
                ['function', 'mb_strlen'],
                ['function', 'ctype_digit'],
                ['function', 'strlen'],
                ['function', 'iconv'],
                ['function', 'bcadd'],
                ['function', 'array_map'],
                ['function', 'mb_strtoupper'],
                ['class', 'Normalizer'],
                ['class', 'NumberFormatter'],
            ],
            array_map(static fn(array $use): array => [$use[0], $use[1]], $found),
        );
        // De todo eso, solo strlen, array_map y la clase de src/ están permitidas.
        $allowed = [];
        foreach ($found as [$kind, $name]) {
            $problem = $kind === 'function'
                ? self::functionProblem($name)
                : self::classProblem($name);
            if ($problem === null) {
                $allowed[] = $name;
            }
        }
        self::assertSame(['RayaCash\Internal\Text', 'strlen', 'array_map'], $allowed);
    }

    /**
     * Las funciones o las clases que usa src/, cada una con dónde (archivo:línea).
     *
     * @param 'function'|'class' $kind
     * @return array<string, list<string>>
     */
    private static function used(string $kind): array
    {
        $root = dirname(__DIR__);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        self::assertGreaterThan(50, count($paths), 'No encontré los archivos de src/.');
        $used = [];
        foreach ($paths as $path) {
            foreach (self::uses((string) file_get_contents($path)) as [$type, $name, $line]) {
                if ($type === $kind) {
                    $used[$name][] = substr($path, strlen($root) + 1) . ":{$line}";
                }
            }
        }
        return $used;
    }

    /**
     * Lo que $code usa de afuera, en orden: las funciones que llama (también las que pasa por
     * nombre, como 'is_string') y las clases que nombra completas (\TypeError) o importa con
     * use. Sin métodos, constructores, declaraciones ni atributos.
     *
     * @return list<array{'function'|'class', string, int}> [tipo, nombre, línea]
     */
    private static function uses(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn(\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $found = [];
        $attribute = 0; // corchetes abiertos dentro de un #[…]
        $depth = 0; // llaves abiertas: un use con llaves en 0 importa una clase
        foreach ($tokens as $i => $token) {
            if ($attribute > 0) {
                $attribute += match ($token->text) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };
                continue;
            }
            if ($token->is(T_ATTRIBUTE)) {
                $attribute = 1;
                continue;
            }
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
            $next = $tokens[$i + 1] ?? null;
            $name = ltrim($token->text, '\\');
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if ($next?->text === '(' && !self::isDeclarationOrMember($tokens, $i)) {
                    $found[] = ['function', $name, $token->line];
                } elseif ($token->is(T_NAME_FULLY_QUALIFIED)) {
                    // \TypeError, new \CurlHandle(…), \Foo::bar() o una constante (\STDERR).
                    $found[] = ['class', $name, $token->line];
                }
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                // Una función pasada por nombre ('is_string'): solo si existe, como callable.
                $text = substr($token->text, 1, -1);
                $isName = preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $text) === 1;
                if ($isName && function_exists($text)) {
                    $found[] = ['function', $text, $token->line];
                }
            } elseif ($token->is(T_USE) && $depth === 0 && $next !== null) {
                if ($next->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $found[] = ['class', ltrim($next->text, '\\'), $next->line];
                }
            }
        }
        return $found;
    }

    /**
     * El nombre que sigue a ->, ?->, ::, function (también function &), new o const no es una
     * función de PHP: es un método, una declaración o un constructor.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isDeclarationOrMember(array $tokens, int $i): bool
    {
        $previous = $tokens[$i - 1] ?? null;
        if ($previous === null) {
            return false;
        }
        if ($previous->is(T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)) {
            $previous = $tokens[$i - 2] ?? $previous;
        }
        return $previous->is([
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_FUNCTION,
            T_NEW,
            T_CONST,
        ]);
    }

    /** null si src/ puede llamarla; si no, por qué. */
    private static function functionProblem(string $name): ?string
    {
        if (!function_exists($name)) {
            return 'no existe en este PHP';
        }
        $function = new \ReflectionFunction($name);
        if (!$function->isInternal()) {
            return 'no es de PHP'; // la de un paquete de Composer no está en producción
        }
        $extension = (string) $function->getExtensionName();
        return in_array(strtolower($extension), self::ALLOWED, true) ? null : "es de {$extension}";
    }

    /** null si src/ puede usarla; si no, por qué. */
    private static function classProblem(string $name): ?string
    {
        // De afuera de src/, solo psr/log: es opcional (composer.json la sugiere) y src/ la nombra
        // solo en tipos, que PHP no carga.
        if (str_starts_with($name, 'Psr\Log\\')) {
            return null;
        }
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return \defined($name) ? null : 'no existe en este PHP'; // \STDERR es una constante
        }
        $class = new \ReflectionClass($name);
        if (!$class->isInternal()) {
            return str_starts_with($name, 'RayaCash\\') ? null : 'es de un paquete de Composer';
        }
        $extension = (string) $class->getExtensionName();
        return in_array(strtolower($extension), self::ALLOWED, true) ? null : "es de {$extension}";
    }
}
