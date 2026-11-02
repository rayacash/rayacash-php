<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\RayaCash;
use RayaCash\Util\LoggerInterface;

/**
 * @internal Lo que guardan RayaCash::set…(): la configuración del estilo global, su requestor y
 * su cliente HTTP propio. De acá, un RayaClient solo lee log y logger (en Log): el logging vale
 * para todo el proceso. Los valores llegan ya validados.
 */
final class GlobalState
{
    private static ?Secret $apiKey = null;
    private static string $apiBase = RayaCash::DEFAULT_API_BASE;
    private static int|float $timeout = RayaCash::DEFAULT_TIMEOUT;
    private static int $maxNetworkRetries = RayaCash::DEFAULT_MAX_NETWORK_RETRIES;
    private static ?HttpClientInterface $httpClient = null;

    /** @var array{name: string, version?: string, url?: string}|null */
    private static ?array $appInfo = null;

    /** @var 'info'|'debug'|null */
    private static ?string $log = null;

    private static \Psr\Log\LoggerInterface|LoggerInterface|null $logger = null;
    private static ?Requestor $requestor = null;
    private static ?HttpClientManager $manager = null;
    private static ?HttpClientInterface $managerClient = null;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    public static function apiKey(): ?Secret
    {
        return self::$apiKey;
    }

    public static function setApiKey(?Secret $apiKey): void
    {
        self::$apiKey = $apiKey;
    }

    public static function apiBase(): string
    {
        return self::$apiBase;
    }

    public static function setApiBase(string $apiBase): void
    {
        self::$apiBase = $apiBase;
    }

    public static function timeout(): int|float
    {
        return self::$timeout;
    }

    public static function setTimeout(int|float $timeout): void
    {
        self::$timeout = $timeout;
    }

    public static function maxNetworkRetries(): int
    {
        return self::$maxNetworkRetries;
    }

    public static function setMaxNetworkRetries(int $retries): void
    {
        self::$maxNetworkRetries = $retries;
    }

    public static function httpClient(): ?HttpClientInterface
    {
        return self::$httpClient;
    }

    public static function setHttpClient(?HttpClientInterface $client): void
    {
        self::$httpClient = $client;
    }

    /** @return array{name: string, version?: string, url?: string}|null */
    public static function appInfo(): ?array
    {
        return self::$appInfo;
    }

    /** @param array{name: string, version?: string, url?: string}|null $appInfo */
    public static function setAppInfo(?array $appInfo): void
    {
        self::$appInfo = $appInfo;
    }

    /** @return 'info'|'debug'|null */
    public static function log(): ?string
    {
        return self::$log;
    }

    /** @param 'info'|'debug'|null $level */
    public static function setLog(?string $level): void
    {
        self::$log = $level;
    }

    public static function logger(): \Psr\Log\LoggerInterface|LoggerInterface|null
    {
        return self::$logger;
    }

    public static function setLogger(\Psr\Log\LoggerInterface|LoggerInterface|null $logger): void
    {
        self::$logger = $logger;
    }

    /** El requestor del estilo global: uno solo, que lee la configuración en cada request. */
    public static function requestor(): Requestor
    {
        return self::$requestor ??= new Requestor(new GlobalConfig());
    }

    /**
     * El cliente HTTP del estilo global. Se busca por identidad en cada request: si cambiaste el
     * de RayaCash::setHttpClient(), se usa el nuevo. El propio se crea una vez y se reusa.
     */
    public static function httpManager(): HttpClientManager
    {
        $client = self::$httpClient;
        if (self::$manager === null || self::$managerClient !== $client) {
            self::$manager = new HttpClientManager($client);
            self::$managerClient = $client;
        }
        return self::$manager;
    }

    /** Vuelve a los valores de fábrica y olvida el requestor y el cliente HTTP propio. */
    public static function reset(): void
    {
        self::$apiKey = null;
        self::$apiBase = RayaCash::DEFAULT_API_BASE;
        self::$timeout = RayaCash::DEFAULT_TIMEOUT;
        self::$maxNetworkRetries = RayaCash::DEFAULT_MAX_NETWORK_RETRIES;
        self::$httpClient = null;
        self::$appInfo = null;
        self::$log = null;
        self::$logger = null;
        self::$requestor = null;
        self::$manager = null;
        self::$managerClient = null;
    }
}
