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
    // logfmt, como en Python y Node: un valor va entre comillas si está vacío o tiene un espacio,
    // =, ", \, un carácter de control (U+0000 a U+001F) o DEL. Adentro, \ y " van como \\ y \",
    // los saltos como \n, \r y \t, y el resto de los controles como \u00XX: lo que llega de
    // afuera (un filtro, el request id o el código de un error) nunca parte la línea, arma otra
    // ni pinta la terminal. Los campos del contexto del logger van escapados igual (sin las
    // comillas): un logger que interpola {campo} o vuelve a poner los saltos del JSON (el de
    // Laravel hace las dos cosas) tampoco parte la línea.
    private const QUOTE = '/[\x00-\x20"=\\\\\x7f]/';
    private const ESCAPE = '/[\x00-\x1f"\\\\\x7f]/';
    private const ESCAPES = ['\\' => '\\\\', '"' => '\"', "\n" => '\n', "\r" => '\r', "\t" => '\t'];

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
            $context = [];
            foreach ($fields as $key => $value) {
                $context[$key] = is_string($value) ? self::escape($value) : $value;
            }
            $level === 'debug' ? $logger->debug($line, $context) : $logger->info($line, $context);
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

    /** El valor como va adentro de las comillas: \\, \", \n, \r, \t y \u00XX. */
    private static function escape(string $text): string
    {
        return (string) preg_replace_callback(
            self::ESCAPE,
            static fn(array $char): string => self::ESCAPES[$char[0]]
                ?? sprintf('\u%04x', ord($char[0])),
            $text,
        );
    }

    /** @param array<string, string|int> $props */
    private static function logfmt(array $props): string
    {
        $parts = [];
        foreach ($props as $key => $value) {
            $text = (string) $value;
            if ($text === '' || \preg_match(self::QUOTE, $text) === 1) {
                $text = '"' . self::escape($text) . '"';
            }
            $parts[] = "{$key}={$text}";
        }
        return \implode(' ', $parts);
    }
}
