<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

/**
 * Lo que se escribe en STDERR mientras corre $fn, sin que llegue a la consola: el capsys de
 * Python y el process.stderr.write de Node. PHP no deja cambiar el STDERR del proceso, así que es
 * un filtro de stream que se queda con cada byte.
 *
 * ```php
 * $err = Stderr::capture(static fn() => $client->balance->retrieve());
 * ```
 */
final class Stderr extends \php_user_filter
{
    private const FILTER = 'rayacash.tests.stderr';

    private static string $captured = '';

    /** @param callable(): mixed $fn */
    public static function capture(callable $fn): string
    {
        if (!in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, self::class);
        }
        self::$captured = '';
        $filter = stream_filter_append(\STDERR, self::FILTER, STREAM_FILTER_WRITE);
        if ($filter === false) {
            throw new \RuntimeException('No pude filtrar STDERR.');
        }
        try {
            $fn();
        } finally {
            stream_filter_remove($filter);
        }
        return self::$captured;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $data = is_string($bucket->data) ? $bucket->data : '';
            self::$captured .= $data;
            $consumed += strlen($data);
        }
        return PSFS_PASS_ON;
    }
}
