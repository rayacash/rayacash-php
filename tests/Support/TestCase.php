<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

use RayaCash\Exception\RayaException;
use RayaCash\Internal\Log;
use RayaCash\Internal\Timing;
use RayaCash\Internal\Transport;
use RayaCash\RayaCash;

/**
 * La base de todos los tests, como conftest.py en Python e isolate() en Node. Cada test arranca:
 *
 * - sin RAYA_SECRET_KEY ni RAYA_LOG de quien corre los tests (ni en getenv(), ni en $_ENV, ni
 *   en $_SERVER) y con el estilo global de fábrica (RayaCash::reset());
 * - con el cliente HTTP propio del SDK cambiado por uno que falla (NO_NETWORK): nada sale a la
 *   red. Los tests usan $this->api, un MockApi nuevo en cada test;
 * - sin dormir en los reintentos: las esperas quedan en $this->sleeps, en segundos, con el
 *   jitter fijo en el máximo (1.0);
 * - con el reloj del SDK en $this->now (null: el real) y su consola en $this->logLines.
 *
 * Los errores se miran con assertThrows() o thrown() (try/catch), nunca con
 * expectExceptionMessage(): así el test sigue después y puede mirar el resto.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Lo que lanza el cliente HTTP propio del SDK en los tests (no es una TransportException). */
    public const NO_NETWORK = 'Los tests del SDK nunca salen a la red: usá MockApi.';

    protected MockApi $api;

    /** @var list<float> Las esperas de los reintentos, en segundos (sin dormir). */
    protected array $sleeps = [];

    /** @var list<string> Lo que el SDK escribió en la consola (STDERR o error_log()). */
    protected array $logLines = [];

    /** Ahora, en segundos unix, para el SDK (webhooks, Retry-After); null es el reloj real. */
    protected ?float $now = null;

    /**
     * Borra las variables de entorno del SDK, vuelve al estilo global de fábrica y pone la
     * guardia de red. Lo llaman tests/bootstrap.php y cada setUp() y tearDown().
     */
    public static function isolate(): void
    {
        foreach (['RAYA_SECRET_KEY', 'RAYA_LOG'] as $name) {
            putenv($name); // sin «=»: la borra
            unset($_ENV[$name], $_SERVER[$name]);
        }
        RayaCash::reset();
        // Como si no hubiera RAYA_LOG (RayaCash::reset() la volvería a leer del entorno).
        Log::resetEnv(null);
        Log::$sink = null;
        Timing::reset();
        // Un RuntimeException, no una TransportException: el SDK no lo envuelve ni lo reintenta.
        Transport::$factory = static function (): never {
            throw new \RuntimeException(self::NO_NETWORK);
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::isolate();
        $this->api = new MockApi();
        Timing::$sleep = function (float $seconds): void {
            $this->sleeps[] = $seconds;
        };
        Timing::$jitter = static fn(): float => 1.0;
        Timing::$now = fn(): float => $this->now ?? microtime(true);
        Log::$sink = function (string $line): void {
            $this->logLines[] = $line;
        };
    }

    protected function tearDown(): void
    {
        self::isolate();
        parent::tearDown();
    }

    /** Lo que el SDK escribió en la consola, una línea por renglón (como capsys en Python). */
    protected function logOutput(): string
    {
        return implode("\n", $this->logLines);
    }

    /**
     * Lo que lanza $fn; falla el test si no lanza nada (como thrown() en Node). Las fallas de
     * PHPUnit (un assert, una request inesperada de MockApi) siguen de largo.
     *
     * @param callable(): mixed $fn
     */
    final protected static function thrown(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\PHPUnit\Exception $failure) {
            throw $failure;
        } catch (\Throwable $error) {
            return $error;
        }
        self::fail('Se esperaba una excepción y no se lanzó ninguna.');
    }

    /**
     * Lo que lanza $fn, que tiene que ser un $class (o una subclase), como pytest.raises. Con
     * $message, el mensaje lo tiene que contener (como match=, pero literal); con $errorCode, es
     * un RayaException con ese getErrorCode(). Devuelve la excepción, para mirar el resto.
     *
     * @template T of \Throwable
     * @param class-string<T> $class
     * @param callable(): mixed $fn
     * @return T
     */
    final protected static function assertThrows(
        string $class,
        callable $fn,
        ?string $message = null,
        ?string $errorCode = null,
    ): \Throwable {
        $error = self::thrown($fn);
        self::assertInstanceOf(
            $class,
            $error,
            sprintf(
                'Lanzó %s en %s:%d: %s',
                $error::class,
                $error->getFile(),
                $error->getLine(),
                $error->getMessage(),
            ),
        );
        if ($message !== null) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        if ($errorCode !== null) {
            self::assertInstanceOf(RayaException::class, $error);
            self::assertSame($errorCode, $error->getErrorCode());
        }
        return $error;
    }

    /**
     * PHP en otro proceso, con $stdin como entrada (o como el código, si no pasás un archivo):
     * el código de salida y lo que escribió (la salida y después los errores). Escribe poco: los
     * pipes no se llenan. Para lo que no se cambia en un proceso que ya arrancó: php.ini,
     * disable_functions, pcre.jit.
     *
     * @param list<string> $arguments
     * @return array{int, string}
     */
    final protected static function php(array $arguments, string $stdin = ''): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertNotFalse($process);
        [$input, $output, $errors] = [$pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null];
        self::assertIsResource($input);
        self::assertIsResource($output);
        self::assertIsResource($errors);
        fwrite($input, $stdin);
        fclose($input);
        $text = (string) stream_get_contents($output) . (string) stream_get_contents($errors);
        fclose($output);
        fclose($errors);
        return [proc_close($process), $text];
    }
}
