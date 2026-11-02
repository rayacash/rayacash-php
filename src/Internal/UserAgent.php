<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\RayaCash;

/**
 * @internal El User-Agent de cada request: rayacash-php/1.0.0 PHP/8.4.3 curl/8.11.1, más tu
 * plugin (app_info). Es lo que ves en Developers › Logs.
 */
final class UserAgent
{
    private static ?string $curl = null;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /** @param array{name: string, version?: string, url?: string}|null $appInfo */
    public static function build(?array $appInfo): string
    {
        // Sin PHP_VERSION: puede traer un sufijo de la distribución (8.3.6-1ubuntu1).
        $ua = sprintf(
            'rayacash-php/%s PHP/%d.%d.%d curl/%s',
            RayaCash::VERSION,
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            PHP_RELEASE_VERSION,
            self::$curl ??= self::curlVersion(),
        );
        return $appInfo === null ? $ua : $ua . ' ' . self::formatAppInfo($appInfo);
    }

    /** @param array{name: string, version?: string, url?: string} $appInfo */
    public static function formatAppInfo(array $appInfo): string
    {
        $text = $appInfo['name'];
        $version = $appInfo['version'] ?? '';
        if ($version !== '') {
            $text .= '/' . $version;
        }
        $url = $appInfo['url'] ?? '';
        if ($url !== '') {
            $text .= ' (' . $url . ')';
        }
        return $text;
    }

    private static function curlVersion(): string
    {
        // Puede estar en disable_functions: el User-Agent dice curl/unknown.
        $info = \function_exists('curl_version') ? curl_version() : false;
        $version = is_array($info) ? $info['version'] ?? null : null;
        return is_string($version) ? $version : 'unknown';
    }
}
