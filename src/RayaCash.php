<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Log;
use RayaCash\Internal\Secret;
use RayaCash\Internal\Validation;
use RayaCash\Util\LoggerInterface;

/**
 * El estilo global, como stripe-php: `RayaCash::setApiKey(…)` y después `Payment::create(…)`.
 *
 * Solo lo usan los métodos estáticos de Balance, Transfer, Payment, OAuth, User y Checkout: un
 * RayaClient tiene su propia configuración y nunca la lee, salvo el logging (setLog(), setLogger()
 * y RAYA_LOG), que vale para todo el proceso. Cada valor se valida al asignarlo, salvo la clave,
 * que se valida en cada request (así un .env vacío no rompe el arranque de tu framework). Los
 * valores duran todo el proceso: en Octane o RoadRunner, no cambies la clave por request (usá un
 * RayaClient por cuenta).
 */
final class RayaCash
{
    /** La versión del SDK: la única fuente (User-Agent, CHANGELOG, release). */
    public const VERSION = '1.1.0';
    public const DEFAULT_API_BASE = 'https://api.raya.cash';
    /** Segundos por intento: el servidor corta a los 60 s y crear un pago puede tardar ~40 s. */
    public const DEFAULT_TIMEOUT = 65;
    /** Segundos para conectar (o el timeout, si es menor). */
    public const DEFAULT_CONNECT_TIMEOUT = 10;
    public const DEFAULT_MAX_NETWORK_RETRIES = 2;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * Tu clave secreta (raya_sk_live_…) o el access_token de una cuenta conectada
     * (raya_at_live_…). Con null, cada request la lee de RAYA_SECRET_KEY.
     *
     * Se valida en cada request, no acá: una clave vacía falla recién al llamar a la API, con
     * API_KEY_MISSING y sin mandar nada.
     */
    public static function setApiKey(#[\SensitiveParameter] ?string $apiKey): void
    {
        GlobalState::setApiKey($apiKey === null ? null : Secret::of($apiKey));
    }

    /** Por defecto, https://api.raya.cash (http solo para localhost). */
    public static function setApiBase(string $apiBase): void
    {
        GlobalState::setApiBase(Validation::apiBase($apiBase));
    }

    /**
     * Segundos por intento (por defecto 65, con 10 para conectar).
     *
     * @param int|float $seconds
     */
    public static function setTimeout(int|float|bool $seconds): void
    {
        // bool en la firma, para rechazarlo: sin strict_types, PHP convertiría true en 1 s (y en
        // setMaxNetworkRetries(), false en 0 reintentos). Un '90' de un .env sigue siendo 90.
        if (is_bool($seconds)) {
            // Sin nombrar el tipo, como Python: sin strict_types, el bool puede venir de un texto
            // que no es un número ('90s' llega como true).
            throw new \TypeError('timeout tiene que ser un número de segundos, como 65.');
        }
        GlobalState::setTimeout(Validation::timeout($seconds));
    }

    /**
     * Reintentos ante cortes, 408, 429, 5xx e IDEMPOTENCY_KEY_IN_USE (por defecto 2; 0 los apaga).
     *
     * @param int $retries
     */
    public static function setMaxNetworkRetries(int|float|bool $retries): void
    {
        // float y bool en la firma, para rechazarlos: sin strict_types, PHP convertiría 1.5 en 1
        // y 0.5 en 0 (sin reintentos), con un aviso que producción no muestra. '2' sigue siendo 2.
        GlobalState::setMaxNetworkRetries(Validation::maxNetworkRetries($retries));
    }

    /** Un cliente HTTP propio (proxy, certificados, tus tests). Con null, el CurlClient del SDK. */
    public static function setHttpClient(?HttpClientInterface $client): void
    {
        GlobalState::setHttpClient($client);
    }

    /** Identifica tu plugin en el User-Agent (lo ves en Developers › Logs). */
    public static function setAppInfo(
        string $name,
        ?string $version = null,
        ?string $url = null,
    ): void {
        $info = ['name' => $name];
        if ($version !== null) {
            $info['version'] = $version;
        }
        if ($url !== null) {
            $info['url'] = $url;
        }
        GlobalState::setAppInfo(Validation::appInfo($info));
    }

    /**
     * 'info' o 'debug' para ver las requests en STDERR (o en error_log() fuera de la consola),
     * también las de cada RayaClient. Pisa RAYA_LOG. Nunca se loguean la clave ni los bodies.
     *
     * @param 'info'|'debug'|null $level
     */
    public static function setLog(?string $level): void
    {
        if ($level !== null && $level !== 'info' && $level !== 'debug') {
            throw new \ValueError("log tiene que ser 'info', 'debug' o null.");
        }
        GlobalState::setLog($level);
    }

    /**
     * Un logger PSR-3 (Monolog, Laravel, Symfony) o un Util\LoggerInterface: recibe cada línea,
     * también las de cada RayaClient, aunque no haya RAYA_LOG (como el logger «rayacash» de
     * Python). Con null, ninguno.
     */
    public static function setLogger(\Psr\Log\LoggerInterface|LoggerInterface|null $logger): void
    {
        GlobalState::setLogger($logger);
    }

    /**
     * Vuelve a los valores de fábrica y olvida el requestor y el cliente HTTP propios: para tus
     * tests del estilo global. No toca las variables de entorno (RAYA_LOG se vuelve a leer).
     */
    public static function reset(): void
    {
        GlobalState::reset();
        Log::forgetEnv();
    }

    public static function getApiBase(): string
    {
        return GlobalState::apiBase();
    }

    public static function getTimeout(): int|float
    {
        return GlobalState::timeout();
    }

    public static function getMaxNetworkRetries(): int
    {
        return GlobalState::maxNetworkRetries();
    }

    public static function getHttpClient(): ?HttpClientInterface
    {
        return GlobalState::httpClient();
    }

    /** @return array{name: string, version?: string, url?: string}|null */
    public static function getAppInfo(): ?array
    {
        return GlobalState::appInfo();
    }

    /** @return 'info'|'debug'|null */
    public static function getLog(): ?string
    {
        return GlobalState::log();
    }

    public static function getLogger(): \Psr\Log\LoggerInterface|LoggerInterface|null
    {
        return GlobalState::logger();
    }
}
