<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\RayaException;
use RayaCash\HttpClient\Request;
use RayaCash\HttpClient\Response;
use RayaCash\HttpClient\TransportException;
use RayaCash\ListObject;
use RayaCash\RayaCash;
use RayaCash\RayaObject;
use RayaCash\RayaResponse;
use RayaCash\Util\Uuid;

/**
 * @internal El núcleo de cada request: headers, encoding, reintentos, errores e hidratación.
 *
 * Lo comparten RayaClient y el estilo global: cada uno le pasa su configuración.
 */
final class Requestor
{
    public function __construct(private readonly RequestorConfig $config) {}

    /**
     * Una request a la API, con sus reintentos: devuelve el objeto de la respuesta.
     *
     * @param array<string, string> $query
     * @param array<array-key, mixed>|\stdClass|null $body
     * @param class-string<RayaObject>|null $expected
     */
    public function request(
        string $method,
        string $path,
        array $query,
        array|\stdClass|null $body,
        RequestOptions $options,
        ?string $expected = null,
    ): RayaObject {
        $prepared = $this->prepare($method, $path, $query, $body, $options);
        $response = $this->send($prepared);
        return $this->hydrate($prepared, $response, $expected);
    }

    /**
     * Como request(), para un endpoint que devuelve un recurso conocido. Si la API respondiera
     * otro tipo de objeto, sale como respuesta inválida: el método no podría devolverlo.
     *
     * @template T of RayaObject
     * @param class-string<T> $class
     * @param array<string, string> $query
     * @param array<array-key, mixed>|\stdClass|null $body
     * @return T
     */
    public function requestAs(
        string $class,
        string $method,
        string $path,
        RequestOptions $options,
        array $query = [],
        array|\stdClass|null $body = null,
    ): RayaObject {
        $object = $this->request($method, $path, $query, $body, $options, $class);
        if (!$object instanceof $class) {
            // Con la respuesta, como todo error de la API: en un POST, la clave permite
            // reintentar sin ejecutarlo dos veces.
            $response = $object->getLastResponse();
            throw new ApiException(
                'La API respondió algo que no es ' . ObjectTypes::noun($class) . '.',
                errorCode: 'INVALID_RESPONSE',
                httpStatus: $response?->httpStatus,
                requestId: $response?->requestId,
                httpHeaders: $response?->headers,
                httpBody: $response?->body,
                idempotencyKey: $response?->idempotencyKey,
            );
        }
        return $object;
    }

    /**
     * Una página de una lista, que recuerda cómo se pidió para pedir las siguientes con los
     * mismos filtros y opciones.
     *
     * @param array<string, string> $query
     * @return ListObject<mixed>
     */
    public function requestList(string $path, array $query, RequestOptions $options): ListObject
    {
        $page = $this->requestAs(ListObject::class, 'GET', $path, $options, $query);
        ObjectToken::of($page)->setContext($path, $query, $options);
        return $page;
    }

    // --- Preparación ------------------------------------------------------------------------

    /**
     * Todo lo que se valida antes de mandar nada, en el orden de Python: método, ruta, clave,
     * body, idempotencia, timeout, reintentos y api_base (las opciones ya vienen revisadas).
     *
     * @param array<string, string> $query
     * @param array<array-key, mixed>|\stdClass|null $body
     */
    private function prepare(
        string $method,
        string $path,
        array $query,
        array|\stdClass|null $body,
        RequestOptions $options,
    ): Prepared {
        $verb = Text::asciiUpper($method);
        if ($verb !== 'GET' && $verb !== 'POST') {
            throw new \ValueError('La API solo acepta GET y POST.');
        }
        if (!\str_starts_with($path, '/')) {
            throw new \ValueError('La ruta tiene que empezar con /, como /v1/balance.');
        }
        if (\preg_match('/\A[\x21-\x7e]+\z/', $path) !== 1 || strpbrk($path, '?#') !== false) {
            throw new \ValueError(
                'La ruta va sin query (usá params) y con los ids codificados con rawurlencode().',
            );
        }

        // Sin clave sale API_KEY_MISSING antes de cualquier I/O.
        $apiKey = $options->apiKey() ?? $this->config->apiKey() ?? Secret::of('');
        Validation::apiKey($apiKey->reveal());

        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->config->userAgent()];
        $content = null;
        if ($body !== null) {
            if ($verb === 'GET') {
                throw new \ValueError('Un GET no lleva body: mandá los filtros en params.');
            }
            $content = Encoding::body($body);
            $headers['Content-Type'] = 'application/json';
        }

        $idempotencyKey = null;
        if ($verb === 'POST') {
            // Una sola clave por llamada, la misma en cada reintento: nunca se ejecuta dos veces.
            $given = $options->get('idempotency_key');
            $idempotencyKey = $given !== null ? Encoding::idempotencyKey($given) : Uuid::v4();
            $headers['Idempotency-Key'] = $idempotencyKey;
        } elseif ($options->has('idempotency_key')) {
            throw new \ValueError('La Idempotency-Key solo va en los POST.');
        }

        $timeout = $options->has('timeout')
            ? (float) Validation::timeout($options->get('timeout'))
            : $this->config->timeout();
        $maxRetries = $options->has('max_network_retries')
            ? Validation::maxNetworkRetries($options->get('max_network_retries'))
            : $this->config->maxNetworkRetries();

        $request = new Request(
            $verb,
            $this->config->apiBase() . $path . Encoding::queryString($query),
            $headers,
            $apiKey,
            $content,
            $timeout,
            \min((float) RayaCash::DEFAULT_CONNECT_TIMEOUT, $timeout),
        );
        return new Prepared($verb, $path, $query, $request, $maxRetries, $idempotencyKey);
    }

    // --- Envío con reintentos ---------------------------------------------------------------

    private function send(Prepared $p): Response
    {
        $client = $this->config->http()->client();
        $retry = 0;
        while (true) {
            self::logRequest($p, $retry);
            $started = Timing::monotonic();
            try {
                $response = $client->send($p->request);
            } catch (TransportException $e) {
                $error = self::connectionError($p, $e);
                if (!$error->shouldRetry() || $retry >= $p->maxRetries) {
                    throw $error;
                }
                $retry++;
                $delay = Retry::backoffDelay($retry);
                self::logRetry($p, $retry, $error->getErrorCode(), $delay);
                Timing::sleep($delay);
                continue;
            }
            $outcome = self::check($p, $response, $started);
            if ($outcome === null) {
                break;
            }
            if ($retry >= $p->maxRetries || !self::shouldRetry($outcome)) {
                throw $outcome;
            }
            $retry++;
            $delay = Retry::retryDelay($retry, $outcome->getRetryAfter());
            self::logRetry($p, $retry, $outcome->getErrorCode(), $delay);
            Timing::sleep($delay);
        }
        return $response;
    }

    private static function shouldRetry(RayaException $error): bool
    {
        return Retry::shouldRetryResponse(
            $error->getHttpStatus() ?? 0,
            $error->getErrorCode(),
            $error->getRetryAfter(),
        );
    }

    /** null si salió bien (2xx); si no, el error que corresponde. */
    private static function check(Prepared $p, Response $response, float $started): ?RayaException
    {
        Log::info('Respuesta de la API de Raya', [
            'method' => $p->method,
            'path' => $p->path,
            'status' => $response->status,
            'request_id' => $response->headers->get('raya-request-id') ?? '',
            'elapsed' => sprintf('%.3Fs', Timing::monotonic() - $started),
        ]);
        if ($response->status >= 200 && $response->status < 300) {
            return null;
        }
        return ErrorFactory::fromResponse(
            $response->status,
            $response->headers,
            $response->body,
            $p->idempotencyKey,
        );
    }

    /**
     * Un corte o un timeout, como ApiConnectionException. Cualquier otra excepción del cliente
     * HTTP sale tal cual.
     */
    private static function connectionError(
        Prepared $p,
        TransportException $e,
    ): ApiConnectionException {
        $message = $e->isTimeout()
            ? 'La API de Raya no respondió a tiempo.'
            : "No pudimos conectarnos con la API de Raya ({$e->getDetail()}).";
        if ($p->method === 'POST') {
            $message .= ' Reintentá con la misma Idempotency-Key: nunca se ejecuta dos veces.';
        }
        return new ApiConnectionException(
            $message,
            errorCode: $e->isTimeout() ? 'TIMEOUT' : 'NETWORK_ERROR',
            idempotencyKey: $p->idempotencyKey,
            previous: $e,
            shouldRetry: $e->isRetryable(),
        );
    }

    private static function logRequest(Prepared $p, int $retry): void
    {
        Log::info('Request a la API de Raya', [
            'method' => $p->method,
            'path' => $p->path,
            'attempt' => $retry + 1,
        ]);
        if ($p->query !== [] || $p->idempotencyKey !== null) {
            $pairs = [];
            foreach ($p->query as $name => $value) {
                $pairs[] = "{$name}={$value}";
            }
            Log::debug('Detalle de la request', [
                'query' => \implode('&', $pairs),
                'idempotency_key' => $p->idempotencyKey ?? '',
            ]);
        }
    }

    private static function logRetry(Prepared $p, int $retry, ?string $reason, float $delay): void
    {
        Log::info('Reintentando la request', [
            'method' => $p->method,
            'path' => $p->path,
            'retry' => $retry,
            'reason' => $reason ?? '',
            'delay' => sprintf('%.2Fs', $delay),
        ]);
    }

    // --- Respuesta --------------------------------------------------------------------------

    /** @param class-string<RayaObject>|null $expected */
    private function hydrate(Prepared $p, Response $response, ?string $expected): RayaObject
    {
        try {
            $data = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!$data instanceof \stdClass) {
            throw new ApiException(
                "La API respondió {$response->status} con un body que no es un objeto JSON.",
                errorCode: 'INVALID_RESPONSE',
                httpStatus: $response->status,
                requestId: $response->headers->get('raya-request-id'),
                httpHeaders: $response->headers,
                httpBody: $response->body,
                idempotencyKey: $p->idempotencyKey,
            );
        }
        $lastResponse = new RayaResponse(
            $response->status,
            $response->headers,
            $response->body,
            $data,
            $p->idempotencyKey,
        );
        return Hydrator::hydrate($data, $this, $expected, $lastResponse);
    }
}
