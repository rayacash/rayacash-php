<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

use RayaCash\HttpClient\Request;

/**
 * Una request que el SDK le mandó a MockApi, con lo que miran los tests ya separado: la ruta tal
 * como viajó (ids codificados incluidos) y la query decodificada. $request es la del SDK, sin
 * tocar.
 */
final class RecordedRequest
{
    public readonly string $method;

    /** La URL completa, con la query. */
    public readonly string $url;

    /** La ruta tal como viajó, sin la query: /v1/transfers/..%2Fbalance (como raw_path). */
    public readonly string $path;

    /** @var array<string, string> Los filtros de la query, decodificados y en orden. */
    public readonly array $query;

    /** El body tal como lo mandó el SDK; null si no llevaba. */
    public readonly ?string $body;

    /** Segundos para todo el intento. */
    public readonly float $timeout;

    /** Segundos para conectar. */
    public readonly float $connectTimeout;

    public function __construct(public readonly Request $request)
    {
        $this->method = $request->method;
        $this->url = $request->url;
        [$beforeQuery, $query] = array_pad(explode('?', $request->url, 2), 2, '');
        $path = parse_url($beforeQuery, PHP_URL_PATH);
        $this->path = is_string($path) ? $path : '';
        $this->query = self::parseQuery($query);
        $this->body = $request->body;
        $this->timeout = $request->timeout;
        $this->connectTimeout = $request->connectTimeout;
    }

    /**
     * Todos los headers, Authorization incluido.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->request->headers();
    }

    /** Un header, sin distinguir mayúsculas; null si no fue. */
    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    /** El body parseado, con arrays comunes (como json.loads en Python). */
    public function json(): mixed
    {
        return json_decode($this->body ?? '', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private static function parseQuery(string $query): array
    {
        $params = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[rawurldecode($name)] = rawurldecode($value);
        }
        return $params;
    }
}
