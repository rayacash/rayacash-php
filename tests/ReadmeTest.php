<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\Checkout;
use RayaCash\Event;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\ListObject;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\RayaObject;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\User;

/**
 * Los ejemplos del README, de examples/ y de scripts/, como test_readme.py en Python y
 * readme.test.ts en Node: cada bloque ```php compila, el de «Tests de tu integración» corre de
 * verdad (sin red: usa un cliente HTTP falso) y lo que nombran del SDK existe.
 */
final class ReadmeTest extends TestCase
{
    /** El cliente falso del único bloque que corre (como `fetch: fakeFetch` en Node). */
    private const OFFLINE_MARKER = '$fakeHttpClient';

    /**
     * Qué es cada variable en los bloques del README y en examples/: así se comprueba que los
     * métodos y los campos que usan existen ($e es cualquier excepción del SDK y $object, el
     * data->object de un evento).
     */
    private const VARIABLES = [
        'client' => [RayaClient::class],
        'connected' => [RayaClient::class],
        'balance' => [Balance::class],
        'payment' => [Payment::class],
        'transfer' => [Transfer::class],
        'deposit' => [Transfer::class],
        'token' => [OAuthToken::class],
        'user' => [User::class],
        'checkout' => [Checkout::class],
        'object' => [Payment::class, Transfer::class, Checkout::class],
        'page' => [ListObject::class],
        'event' => [Event::class],
        'e' => [
            RayaException::class,
            ApiConnectionException::class,
            AuthenticationException::class,
            InvalidRequestException::class,
            SignatureVerificationException::class,
        ],
    ];

    /**
     * Las variables de los ejemplos que no son del SDK (tu pedido, el request de tu framework…):
     * no se miran. Una que no está acá ni en VARIABLES falla: así ninguna queda sin mirar.
     */
    private const FOREIGN = [
        'this',
        'request',
        'middleware',
        'order',
        'payout',
        'refund',
        'start',
        'today',
        'account',
    ];

    public function testTheReadmeHasPhpExamples(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::blocks()));
    }

    /** @return iterable<string, array{string}> */
    public static function readmeBlocks(): iterable
    {
        foreach (self::blocks() as $block) {
            yield "README.md:{$block['line']}" => [$block['code']];
        }
    }

    #[DataProvider('readmeBlocks')]
    public function testReadmeBlockCompiles(string $code): void
    {
        // Sin «<?php», php -l no ve código: a los bloques que no lo traen se lo sumamos en la
        // misma línea, así el número de línea de un error es el del bloque.
        $source = str_starts_with($code, '<?php') ? $code : '<?php ' . $code;
        [$status, $output] = self::php(['-l'], $source);
        self::assertSame(0, $status, $output);
    }

    public function testTheOfflineTestingExampleRuns(): void
    {
        $blocks = array_values(array_filter(
            self::blocks(),
            static fn(array $block): bool => str_contains($block['code'], self::OFFLINE_MARKER),
        ));
        self::assertCount(1, $blocks, 'Un solo bloque del README usa ' . self::OFFLINE_MARKER);
        $block = $blocks[0];

        // Es el de «Tests de tu integración».
        $readme = self::readme();
        $section = strpos($readme, "\n## Tests de tu integración\n");
        self::assertNotFalse($section);
        $next = strpos($readme, "\n## ", $section + 1);
        self::assertGreaterThan($section, $block['offset']);
        self::assertLessThan($next === false ? PHP_INT_MAX : $next, $block['offset']);

        // Corre en otro proceso, con el autoload del repo y sin poder salir a la red: el cliente
        // HTTP propio del SDK falla, como en estos tests (además de los proxies y del
        // --network none).
        $prepend = tempnam(sys_get_temp_dir(), 'rayacash-readme-');
        self::assertNotFalse($prepend);
        try {
            file_put_contents($prepend, implode("\n", [
                '<?php',
                'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';',
                'RayaCash\Internal\Transport::$factory = static function (): never {',
                '    throw new RuntimeException(' . var_export(self::NO_NETWORK, true) . ');',
                '};',
            ]));
            // assert() prendido, y sin los avisos de Xdebug (si está cargado) en la salida.
            [$status, $output] = self::php(
                [
                    '-d', 'zend.assertions=1',
                    '-d', 'xdebug.mode=off',
                    '-d', "auto_prepend_file={$prepend}",
                ],
                '<?php ' . $block['code'],
            );
        } finally {
            unlink($prepend);
        }
        self::assertSame([0, ''], [$status, $output]);
    }

    public function testTheDocblocksSayWeForWebhookDestinations(): void
    {
        // Los destinos de Developers › Webhooks son we_… (whe_ fue el prefijo de un borrador).
        $files = self::sourceFiles(dirname(__DIR__) . '/src');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringNotContainsString('whe_', (string) file_get_contents($file), $file);
        }
        self::assertStringNotContainsString('whe_', self::readme());
    }

    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        foreach (['examples', 'scripts'] as $folder) {
            foreach (glob(dirname(__DIR__) . "/{$folder}/*.php") ?: [] as $file) {
                yield "{$folder}/" . basename($file) => [$file];
            }
        }
    }

    #[DataProvider('phpFiles')]
    public function testExamplesAndScriptsCompile(string $file): void
    {
        [$status, $output] = self::php(['-l', $file]);
        self::assertSame(0, $status, $output);
    }

    /** @return iterable<string, array{string}> */
    public static function snippets(): iterable
    {
        foreach (self::blocks() as $block) {
            yield "README.md:{$block['line']}" => [$block['code']];
        }
        foreach (glob(dirname(__DIR__) . '/examples/*.php') ?: [] as $file) {
            yield 'examples/' . basename($file) => [(string) file_get_contents($file)];
        }
    }

    /**
     * Lo que los ejemplos nombran del SDK existe: las clases que importan, sus métodos estáticos
     * y constantes, y los métodos y campos de $client, $payment, $transfer… (también en los
     * comentarios). Los de Laravel y Symfony no pasan por PHPStan: esto los cubre.
     */
    #[DataProvider('snippets')]
    public function testSnippetsOnlyUseWhatTheSdkHas(string $code): void
    {
        $problems = [];
        // El cliente es $client en todos lados: un $raya viejo falla, también sin ->.
        if (preg_match('/\$raya\b/', $code) === 1) {
            $problems[] = '$raya: el cliente se llama $client';
        }
        $imports = self::sdkImports($code);
        foreach ($imports as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                $problems[] = "{$class} no existe";
            }
        }

        preg_match_all('/\b([A-Z]\w*)::(\w+)(\s*\()?/', $code, $statics, PREG_SET_ORDER);
        foreach ($statics as $match) {
            $class = $imports[$match[1]] ?? null;
            $member = $match[2];
            if ($class === null || $member === 'class' || !class_exists($class)) {
                continue;
            }
            $name = "{$match[1]}::{$member}";
            if (($match[3] ?? '') === '') {
                if (!defined("{$class}::{$member}")) {
                    $problems[] = "{$name} no existe";
                }
            } elseif (!method_exists($class, $member)) {
                $problems[] = "{$name}() no existe";
            } elseif (!(new \ReflectionMethod($class, $member))->isStatic()) {
                $problems[] = "{$name}() no es estático";
            }
        }

        preg_match_all(
            '/\$(\w+)->(\w+)(\s*\()?(?:->(\w+)(\s*\()?)?/',
            $code,
            $chains,
            PREG_SET_ORDER,
        );
        foreach ($chains as $match) {
            $classes = self::VARIABLES[$match[1]] ?? null;
            if ($classes === null) {
                if (!in_array($match[1], self::FOREIGN, true)) {
                    $problems[] = "\${$match[1]}: sumala a VARIABLES (si es del SDK) o a FOREIGN";
                }
                continue;
            }
            $member = $match[2];
            $call = ($match[3] ?? '') !== '';
            $owner = self::owner($classes, $member, $call);
            if ($owner === null) {
                $problems[] = "\${$match[1]}->{$member}" . ($call ? '()' : '') . ' no existe';
                continue;
            }
            // Un nivel más: $client->payments->create(), $payment->bank_transfer->cvu.
            $next = $match[4] ?? '';
            $nested = $call || $next === '' ? null : self::memberClass($owner, $member);
            $nextCall = ($match[5] ?? '') !== '';
            if ($nested !== null && self::owner([$nested], $next, $nextCall) === null) {
                $problems[] = "\${$match[1]}->{$member}->{$next}" . ($nextCall ? '()' : '')
                    . ' no existe';
            }
        }
        self::assertSame([], $problems);
    }

    public function testTheTableOfContentsMatchesTheSections(): void
    {
        $readme = self::readme();
        preg_match_all('/^## (.+)$/m', $readme, $headings);
        $sections = array_values(array_diff($headings[1], ['Contenido']));
        preg_match('/^## Contenido\n\n((?:- .+\n)+)/m', $readme, $toc);
        preg_match_all('/^- \[(.+)\]\(#(.+)\)$/m', $toc[1] ?? '', $entries, PREG_SET_ORDER);
        self::assertSame(
            array_map(static fn(string $title): array => [$title, self::anchor($title)], $sections),
            array_map(static fn(array $entry): array => [$entry[1], $entry[2]], $entries),
        );
    }

    // --- Ayudas -------------------------------------------------------------------------------

    /**
     * Los .php bajo $dir, recursivo.
     *
     * @return list<string>
     */
    private static function sourceFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            if (is_dir($path)) {
                $files = [...$files, ...self::sourceFiles($path)];
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private static function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/README.md');
    }

    /**
     * Los bloques ```php del README: el código, la línea donde empieza y su posición.
     *
     * @return list<array{code: string, line: int, offset: int}>
     */
    private static function blocks(): array
    {
        $readme = self::readme();
        preg_match_all('/^```php\n(.*?)^```$/ms', $readme, $matches, PREG_OFFSET_CAPTURE);
        $blocks = [];
        foreach ($matches[1] as [$code, $offset]) {
            $blocks[] = [
                'code' => $code,
                'line' => substr_count(substr($readme, 0, $offset), "\n") + 1,
                'offset' => $offset,
            ];
        }
        return $blocks;
    }

    /**
     * Las clases del SDK que importa un ejemplo, por el nombre con que las usa.
     *
     * @return array<string, string>
     */
    private static function sdkImports(string $code): array
    {
        preg_match_all(
            '/^use (RayaCash\\\\[\w\\\\]+)(?: as (\w+))?;$/m',
            $code,
            $matches,
            PREG_SET_ORDER,
        );
        $imports = [];
        foreach ($matches as $match) {
            $class = $match[1];
            $alias = ($match[2] ?? '') !== ''
                ? $match[2]
                : substr($class, (int) strrpos($class, '\\') + 1);
            $imports[$alias] = $class;
        }
        return $imports;
    }

    /**
     * La primera de $classes que tiene ese método (con $call) o ese campo: una propiedad de
     * verdad o, en los objetos de la API, un @property-read. null si ninguna.
     *
     * @param list<class-string> $classes
     * @return class-string|null
     */
    private static function owner(array $classes, string $member, bool $call): ?string
    {
        foreach ($classes as $class) {
            $found = $call
                ? method_exists($class, $member)
                : (property_exists($class, $member)
                    || isset(self::documentedFields($class)[$member]));
            if ($found) {
                return $class;
            }
        }
        return null;
    }

    /**
     * La clase de un campo: el tipo de una propiedad (los servicios de RayaClient) o el que
     * documenta el objeto (Payment\BankTransfer…). null si no es una sola clase.
     *
     * @param class-string $class
     * @return class-string|null
     */
    private static function memberClass(string $class, string $member): ?string
    {
        if (property_exists($class, $member)) {
            $type = (new \ReflectionProperty($class, $member))->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            return class_exists($name) ? $name : null;
        }
        $type = str_replace(['|null', 'null|'], '', self::documentedFields($class)[$member] ?? '');
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $nested = "{$namespace}\\{$type}";
        return preg_match('/\A[A-Z][\w\\\\]*\z/', $type) === 1 && class_exists($nested)
            ? $nested
            : null;
    }

    /**
     * Los campos que documenta un objeto de la API (@property-read), con su tipo.
     *
     * @param class-string $class
     * @return array<string, string>
     */
    private static function documentedFields(string $class): array
    {
        if (!is_a($class, RayaObject::class, true)) {
            return [];
        }
        $doc = (string) (new \ReflectionClass($class))->getDocComment();
        preg_match_all('/@property-read\s+([^$]*?)\s*\$(\w+)/', $doc, $matches, PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as $match) {
            $fields[$match[2]] = $match[1];
        }
        return $fields;
    }

    /** El ancla que arma GitHub para un título: minúsculas, sin puntuación y con guiones. */
    private static function anchor(string $title): string
    {
        $slug = (string) preg_replace('/[^\p{L}\p{N}\- ]/u', '', strtolower($title));
        return str_replace(' ', '-', $slug);
    }
}
