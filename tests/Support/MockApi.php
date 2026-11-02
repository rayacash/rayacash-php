<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

use PHPUnit\Framework\Assert;
use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\HttpClient\Request;
use RayaCash\HttpClient\Response;
use RayaCash\RayaClient;

/**
 * Un servidor falso para los tests: nada sale a la red. Devuelve las respuestas encoladas, en
 * orden, y guarda cada request, como MockAPI en Python y en Node.
 *
 * ```php
 * $this->api->add(200, Fixtures::BALANCE);
 * $balance = $this->api->client()->balance->retrieve();
 * $this->api->last()->path; // '/v1/balance'
 * ```
 *
 * Una request sin respuesta encolada falla el test («Request inesperada: GET …»). Se puede usar
 * como cliente de un RayaClient (client()) o del estilo global (RayaCash::setHttpClient()).
 */
final class MockApi implements HttpClientInterface
{
    /** @var list<RecordedRequest> Cada request que mandó el SDK, en orden. */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    private int $count = 0;

    /**
     * Encola una respuesta con $status y el JSON de $json (o $text tal cual, si lo pasás).
     *
     * Lleva Raya-Request-Id: req_test00000000000000000001 (…2, …3 en las siguientes) y, salvo
     * con $text, Content-Type: application/json. Tus $headers pisan esos dos.
     *
     * @param array<string, string|int|list<string>> $headers
     */
    public function add(
        int $status = 200,
        mixed $json = null,
        array $headers = [],
        ?string $text = null,
    ): void {
        $this->count++;
        $defaults = ['Raya-Request-Id' => sprintf('req_test%020d', $this->count)];
        if ($text === null) {
            $defaults['Content-Type'] = 'application/json';
        }
        // Un header tuyo pisa al de serie aunque lo escribas con otras mayúsculas.
        $given = array_map(self::lower(...), array_keys($headers));
        foreach (array_keys($defaults) as $name) {
            if (in_array(self::lower($name), $given, true)) {
                unset($defaults[$name]);
            }
        }
        $body = $text ?? json_encode(
            $json,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $this->queue[] = new Response($status, $defaults + $headers, $body);
    }

    /**
     * Encola una respuesta de error: {"error": {"code", "message", "data"}}. data va como objeto
     * JSON ({} si está vacío), como el dict de Python.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, string|int|list<string>> $headers
     */
    public function addError(
        int $status,
        string $code,
        string $message = 'Mensaje de prueba.',
        ?array $data = null,
        array $headers = [],
    ): void {
        $error = [
            'code' => $code,
            'message' => $message,
            'data' => $data === null ? null : (object) $data,
        ];
        $this->add($status, ['error' => $error], $headers);
    }

    /**
     * La próxima request falla con $error. Para un corte o un timeout, una TransportException
     * (TransportException::network('sin red'), TransportException::timeout('lento')): el SDK la
     * convierte en ApiConnectionException. Cualquier otra sale tal cual.
     */
    public function failWith(\Throwable $error): void
    {
        $this->queue[] = $error;
    }

    /** La anota y devuelve lo próximo de la cola (o lanza lo que encolaste con failWith()). */
    public function send(Request $request): Response
    {
        $this->requests[] = new RecordedRequest($request);
        $item = array_shift($this->queue);
        if ($item === null) {
            // Assert::fail() y no un throw: así la falla cuenta como aserción y el test no sale
            // además como «risky» por no tener ninguna.
            Assert::fail("Request inesperada: {$request->method} {$request->url}");
        }
        if ($item instanceof \Throwable) {
            throw $item;
        }
        return $item;
    }

    /** La última request (falla el test si no hubo ninguna). */
    public function last(): RecordedRequest
    {
        return $this->requests[count($this->requests) - 1]
            ?? Assert::fail('No hubo ninguna request.');
    }

    /** El body de la última request, parseado con arrays comunes. */
    public function lastJson(): mixed
    {
        return $this->last()->json();
    }

    /** Cuántas respuestas quedan en la cola. */
    public function pending(): int
    {
        return count($this->queue);
    }

    /**
     * Un RayaClient que habla con este servidor falso. Con $apiKey null, lee RAYA_SECRET_KEY.
     * $config son las opciones de RayaClient (timeout, api_base…), también inválidas: como
     * **kwargs en Python, acá no se chequean.
     *
     * @param array<string, mixed> $config
     */
    public function client(?string $apiKey = Fixtures::TEST_KEY, array $config = []): RayaClient
    {
        // @phpstan-ignore argument.type (los tests pasan también opciones inválidas a propósito)
        return new RayaClient($apiKey, $config + ['http_client' => $this]);
    }

    /** Minúsculas ASCII: en PHP 8.1, strtolower depende del locale (y un test lo puede cambiar). */
    private static function lower(int|string $name): string
    {
        return strtr((string) $name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
