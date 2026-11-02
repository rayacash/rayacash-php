<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Los logs del SDK, en logfmt como en Python. Van a STDERR en la consola (o a
 * error_log() en FPM y Apache) cuando RAYA_LOG o RayaCash::setLog() lo piden, y siempre al
 * logger de RayaCash::setLogger(). Nunca la clave ni los bodies: llevan nombres, CUIT y CBU.
 */
final class Log
{
    /** @var (\Closure(string): void)|null Adónde va cada línea de la consola (los tests). */
    public static ?\Closure $sink = null;

    /** RAYA_LOG, leída una sola vez (false: todavía no se leyó). */
    private static string|false|null $env = false;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** Fija RAYA_LOG como si se hubiera leído del entorno (null: sin RAYA_LOG). */
    public static function resetEnv(?string $value = null): void
    {
        self::$env = $value;
    }

    /** RAYA_LOG se vuelve a leer del entorno en la próxima línea (lo usa RayaCash::reset()). */
    public static function forgetEnv(): void
    {
        self::$env = false;
    }

    /** @param array<string, string|int> $fields */
    public static function info(string $message, array $fields): void
    {
        self::emit('info', $message, $fields);
    }

    /** @param array<string, string|int> $fields */
    public static function debug(string $message, array $fields): void
    {
        self::emit('debug', $message, $fields);
    }

    /** @param array<string, string|int> $fields */
    private static function emit(string $level, string $message, array $fields): void
    {
        $console = self::consoleLevel();
        $toConsole = $level === 'debug' ? $console === 'debug' : $console !== null;
        $logger = GlobalState::logger();
        if (!$toConsole && $logger === null) {
            return;
        }
        $line = self::logfmt(['message' => $message] + $fields);
        if ($toConsole) {
            self::write($line);
        }
        if ($logger !== null) {
            $level === 'debug' ? $logger->debug($line, $fields) : $logger->info($line, $fields);
        }
    }

    /** @return 'info'|'debug'|null */
    private static function consoleLevel(): ?string
    {
        if (self::$env === false) {
            self::$env = Env::get('RAYA_LOG');
        }
        $level = GlobalState::log() ?? self::$env;
        return $level === 'info' || $level === 'debug' ? $level : null;
    }

    private static function write(string $line): void
    {
        if (self::$sink !== null) {
            (self::$sink)($line);
        } elseif (PHP_SAPI === 'cli' && \defined('STDERR')) {
            fwrite(\STDERR, $line . "\n");
        } elseif (\function_exists('error_log')) {
            // En FPM, php://stderr se pierde (salvo catch_workers_output): error_log() llega. Si
            // está en disable_functions, la línea solo va al logger de setLogger().
            error_log($line);
        }
    }

    /** @param array<string, string|int> $props */
    private static function logfmt(array $props): string
    {
        $parts = [];
        foreach ($props as $key => $value) {
            $text = (string) $value;
            if ($text === '' || strpbrk($text, ' ="\\') !== false) {
                $text = '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
            }
            $parts[] = "{$key}={$text}";
        }
        return \implode(' ', $parts);
    }
}
