<?php

declare(strict_types=1);

// Servidor HTTP mínimo para TransportTest: el CurlClient del SDK le habla por un socket de verdad,
// sin salir de 127.0.0.1. Lo arranca Support\LoopbackServer con proc_open(PHP_BINARY …). php -S
// no sirve: no escucha en el puerto 0 ni deja cortar, colgar o redirigir a pedido.
//
// Escucha en 127.0.0.1, en un puerto libre, y lo avisa con «PORT <n>» en STDOUT. Después, por
// cada request, escribe en STDOUT una línea JSON (método, ruta, headers y body) ANTES de
// responder: cuando el SDK recibe la respuesta, o el error, la request ya está anotada. Una
// request por conexión: siempre responde con «Connection: close».
//
// Qué responde sale del primer segmento de la ruta, que el test pone en api_base
// (http://127.0.0.1:<n>/<escenario>-<id>, con un id nuevo en cada LoopbackServer::base()):
//
//   json-<status>-<FIXTURE>  ese status con el JSON de Fixtures::<FIXTURE>
//   redirect                 301 a http://127.0.0.1:<n>/v1/otra (seguirlo sería otra request)
//   cut-once                 la primera vez se corta a mitad del body; después, BALANCE
//   cut                      siempre se corta a mitad del body (promete 100 bytes, manda 14)
//   close                    cierra la conexión sin responder
//   hang                     nunca responde (cierra cuando el cliente se va)
//   continue                 un «100 Continue» con headers propios y después BALANCE
//   lowercase                BALANCE con los nombres de los headers en minúscula (y uno repetido)
//   gzip                     BALANCE comprimido con gzip
//
// Otro escenario da 404 UNKNOWN_SCENARIO. Termina solo cuando se cierra su STDIN (lo hace
// LoopbackServer::stop(), o el sistema si PHPUnit se cae): nunca queda huérfano. Cualquier aviso
// de PHP va a STDERR, y LoopbackServer::stop() falla el test si hubo alguno.
//
// Con dos argumentos, el certificado y su clave privada (LoopbackServer::start(tls: true)),
// habla HTTPS: https://127.0.0.1:<n>. Si el cliente no confía en el certificado o en su nombre,
// corta la conexión: se cierra sin anotar nada y sin aviso (no es un error del servidor).

use RayaCash\Tests\Support\Fixtures;

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    fwrite(STDERR, "loopback-server: {$message} ({$file}:{$line})\n");
    return true;
});

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Un body más grande se anota solo con su largo y su sha256 (no entra entero por el pipe). */
const LOOPBACK_BODY_LIMIT = 8192;

const LOOPBACK_REASONS = [
    100 => 'Continue',
    200 => 'OK',
    201 => 'Created',
    301 => 'Moved Permanently',
    404 => 'Not Found',
];

/** @var array{local_cert: string, local_pk: string}|null $tls */
$tls = isset($argv[1], $argv[2]) ? ['local_cert' => $argv[1], 'local_pk' => $argv[2]] : null;

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "loopback-server: no pude escuchar en 127.0.0.1 ({$errorMessage}).\n");
    exit(1);
}
$address = (string) stream_socket_get_name($server, false);
$port = (int) substr($address, (int) strrpos($address, ':') + 1);
loopback_print("PORT {$port}");

/** @var array<int, resource> $sockets Las conexiones abiertas, por id. */
$sockets = [];
/** @var array<int, string> $buffers Lo recibido de cada conexión que todavía no es una request. */
$buffers = [];
/** @var array<int, true> $continued Las conexiones a las que ya se les mandó «100 Continue». */
$continued = [];
/** @var array<string, int> $calls Cuántas requests llegaron con cada id de escenario. */
$calls = [];

while (true) {
    $read = [STDIN, $server, ...array_values($sockets)];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, null) === false) {
        fwrite(STDERR, "loopback-server: falló stream_select().\n");
        exit(1);
    }
    foreach ($read as $socket) {
        if ($socket === STDIN) {
            // Se cerró STDIN: LoopbackServer::stop(), o PHPUnit terminó.
            if (fread(STDIN, 1024) === '' && feof(STDIN)) {
                exit(0);
            }
            continue;
        }
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);
            if ($client !== false && $tls !== null && !loopback_handshake($client, $tls)) {
                fclose($client);
                $client = false;
            }
            if ($client !== false) {
                stream_set_blocking($client, false);
                $sockets[get_resource_id($client)] = $client;
                $buffers[get_resource_id($client)] = '';
            }
            continue;
        }
        $id = get_resource_id($socket);
        $chunk = loopback_read($socket, $tls !== null);
        if ($chunk === false || $chunk === '') {
            if ($chunk === false || feof($socket)) {
                fclose($socket);
                unset($sockets[$id], $buffers[$id], $continued[$id]);
            }
            continue;
        }
        if (!isset($buffers[$id])) {
            continue; // «hang»: su request ya está anotada y no le respondemos nunca
        }
        $buffers[$id] .= $chunk;
        $request = loopback_parse($buffers[$id]);
        if ($request === null) {
            if (!isset($continued[$id]) && loopback_expects_continue($buffers[$id])) {
                // Si el cliente espera permiso para mandar el body, que no pierda un segundo.
                loopback_send($socket, "HTTP/1.1 100 Continue\r\n\r\n");
                $continued[$id] = true;
            }
            continue;
        }
        unset($buffers[$id]);

        [$scenarioId, $path] = loopback_split_target($request['target']);
        $scenario = (string) preg_replace('/-[0-9]+\z/', '', $scenarioId);
        $calls[$scenarioId] = ($calls[$scenarioId] ?? 0) + 1;
        $body = $request['body'];
        loopback_print(json_encode(
            [
                'id' => $scenarioId,
                'scenario' => $scenario,
                'method' => $request['method'],
                'target' => $request['target'],
                'path' => $path,
                'protocol' => $request['protocol'],
                'headers' => $request['headers'],
                'body' => strlen($body) <= LOOPBACK_BODY_LIMIT ? base64_encode($body) : null,
                'length' => strlen($body),
                'sha256' => hash('sha256', $body),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));

        [$reply, $close] = loopback_reply($scenario, $calls[$scenarioId], $port);
        if ($reply !== '') {
            loopback_send($socket, $reply);
        }
        if ($close) {
            fclose($socket);
            unset($sockets[$id], $continued[$id]);
        }
    }
}

/**
 * El handshake de TLS de una conexión nueva, como servidor (bloquea hasta que termina). false si
 * el cliente lo cortó: lo hace curl cuando no confía en el certificado (curl 60).
 *
 * @param resource $client
 * @param array{local_cert: string, local_pk: string} $tls
 */
function loopback_handshake($client, array $tls): bool
{
    foreach ($tls as $option => $file) {
        stream_context_set_option($client, 'ssl', $option, $file);
    }
    stream_set_blocking($client, true);
    // El aviso de un handshake cortado («unknown ca») es lo que el test espera: no va a STDERR.
    set_error_handler(static fn(): bool => true);
    try {
        return stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
    } finally {
        restore_error_handler();
    }
}

/**
 * Lo que llegó por una conexión. Con TLS, curl puede cortarla de golpe, sin cerrar TLS (lo hace
 * si el certificado no es del host al que llamó, después del handshake): el aviso de esa lectura
 * tampoco va a STDERR, y la conexión queda cerrada.
 *
 * @param resource $socket
 */
function loopback_read($socket, bool $tls): string|false
{
    if (!$tls) {
        return fread($socket, 65536);
    }
    set_error_handler(static fn(): bool => true);
    try {
        return fread($socket, 65536);
    } finally {
        restore_error_handler();
    }
}

/** Una línea para LoopbackServer, ya en el pipe cuando vuelve. */
function loopback_print(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

/**
 * Escribe todo $data en el socket (las conexiones leen sin bloquear; para escribir, bloquean).
 *
 * @param resource $socket
 */
function loopback_send($socket, string $data): void
{
    stream_set_blocking($socket, true);
    while ($data !== '') {
        $written = fwrite($socket, $data);
        if ($written === false || $written === 0) {
            fwrite(STDERR, "loopback-server: no pude escribir la respuesta.\n");
            break;
        }
        $data = substr($data, $written);
    }
    stream_set_blocking($socket, false);
}

/** Minúsculas ASCII, sin depender del locale. */
function loopback_lower(string $text): string
{
    return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

/**
 * La request completa que hay en $buffer, o null si todavía falta una parte: la línea de la
 * request, los headers en orden y el body (por Content-Length, o en chunks).
 *
 * @return array{
 *     method: string,
 *     target: string,
 *     protocol: string,
 *     headers: list<array{string, string}>,
 *     body: string,
 * }|null
 */
function loopback_parse(string $buffer): ?array
{
    $end = strpos($buffer, "\r\n\r\n");
    if ($end === false) {
        return null;
    }
    $lines = explode("\r\n", substr($buffer, 0, $end));
    $start = explode(' ', array_shift($lines) ?? '', 3);
    $headers = [];
    $length = 0;
    $chunked = false;
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = substr($line, 0, $colon);
        $value = trim(substr($line, $colon + 1), " \t");
        $headers[] = [$name, $value];
        if (loopback_lower($name) === 'content-length') {
            $length = (int) $value;
        } elseif (loopback_lower($name) === 'transfer-encoding') {
            $chunked = str_contains(loopback_lower($value), 'chunked');
        }
    }
    $rest = substr($buffer, $end + 4);
    if ($chunked) {
        $body = loopback_dechunk($rest);
        if ($body === null) {
            return null;
        }
    } elseif (strlen($rest) >= $length) {
        $body = substr($rest, 0, $length);
    } else {
        return null;
    }
    return [
        'method' => $start[0],
        'target' => $start[1] ?? '',
        'protocol' => $start[2] ?? '',
        'headers' => $headers,
        'body' => $body,
    ];
}

/** El body de una request en chunks, o null si todavía no llegó el último. */
function loopback_dechunk(string $data): ?string
{
    $body = '';
    $offset = 0;
    while (true) {
        $lineEnd = strpos($data, "\r\n", $offset);
        if ($lineEnd === false) {
            return null;
        }
        $sizeText = trim(explode(';', substr($data, $offset, $lineEnd - $offset))[0]);
        if (preg_match('/\A[0-9a-fA-F]{1,8}\z/', $sizeText) !== 1) {
            return $body; // un chunk mal armado: lo anotado alcanza para que el test falle
        }
        $size = (int) hexdec($sizeText);
        if ($size === 0) {
            // Sin trailers, termina en una línea vacía.
            return strpos($data, "\r\n", $lineEnd + 2) !== false ? $body : null;
        }
        if (strlen($data) < $lineEnd + 2 + $size + 2) {
            return null;
        }
        $body .= substr($data, $lineEnd + 2, $size);
        $offset = $lineEnd + 2 + $size + 2;
    }
}

/** Si los headers ya llegaron y piden «Expect: 100-continue» antes del body. */
function loopback_expects_continue(string $buffer): bool
{
    $end = strpos($buffer, "\r\n\r\n");
    if ($end === false) {
        return false;
    }
    return preg_match('/\r\nexpect:[ \t]*100-continue/i', substr($buffer, 0, $end)) === 1;
}

/**
 * El id del escenario (el primer segmento de la ruta) y el resto de la ruta, con la query tal
 * como viajó: /json-200-BALANCE-3/v1/balance da ['json-200-BALANCE-3', '/v1/balance'].
 *
 * @return array{string, string}
 */
function loopback_split_target(string $target): array
{
    $path = substr($target, 0, strcspn($target, '?'));
    $segment = explode('/', ltrim($path, '/'))[0];
    $rest = (string) substr($target, strlen($segment) + 1);
    return [$segment, str_starts_with($rest, '/') ? $rest : '/' . $rest];
}

/**
 * Lo que se responde (puede ser nada) y si después se cierra la conexión.
 *
 * @return array{string, bool}
 */
function loopback_reply(string $scenario, int $calls, int $port): array
{
    if (preg_match('/\Ajson-([0-9]{3})-([A-Z_]+)\z/', $scenario, $match) === 1) {
        $fixture = Fixtures::class . '::' . $match[2];
        if (!defined($fixture)) {
            return [loopback_unknown($scenario), true];
        }
        return [loopback_json((int) $match[1], constant($fixture)), true];
    }
    return match ($scenario) {
        'redirect' => [
            loopback_response(301, [
                ['Location', "http://127.0.0.1:{$port}/v1/otra"],
                ['Content-Length', '0'],
                ['Connection', 'close'],
            ], ''),
            true,
        ],
        'cut-once' => [$calls === 1 ? loopback_cut() : loopback_json(200, Fixtures::BALANCE), true],
        'cut' => [loopback_cut(), true],
        'close' => ['', true],
        'hang' => ['', false],
        // Un «100 Continue» que nadie pidió, con su propio Raya-Request-Id: si sus headers se
        // mezclaran con los de la respuesta, el request id saldría «req_interim, req_loopback».
        'continue' => [
            "HTTP/1.1 100 Continue\r\nRaya-Request-Id: req_interim\r\nX-Interim: si\r\n\r\n"
                . loopback_json(200, Fixtures::BALANCE),
            true,
        ],
        'lowercase' => [loopback_lowercase(), true],
        'gzip' => [loopback_gzip(), true],
        default => [loopback_unknown($scenario), true],
    };
}

/** @param list<array{string, string}> $headers En orden; un nombre puede repetirse. */
function loopback_response(int $status, array $headers, string $body): string
{
    $text = "HTTP/1.1 {$status} " . (LOOPBACK_REASONS[$status] ?? 'Respuesta') . "\r\n";
    foreach ($headers as [$name, $value]) {
        $text .= "{$name}: {$value}\r\n";
    }
    return $text . "\r\n" . $body;
}

function loopback_encode(mixed $data): string
{
    return json_encode(
        $data,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
    );
}

/** Como la API: JSON, Raya-Request-Id y Content-Length. */
function loopback_json(int $status, mixed $data): string
{
    $body = loopback_encode($data);
    return loopback_response($status, [
        ['Content-Type', 'application/json'],
        ['Raya-Request-Id', 'req_loopback'],
        ['Content-Length', (string) strlen($body)],
        ['Connection', 'close'],
    ], $body);
}

function loopback_unknown(string $scenario): string
{
    $error = [
        'code' => 'UNKNOWN_SCENARIO',
        'message' => "El servidor de prueba no conoce el escenario «{$scenario}».",
        'data' => null,
    ];
    return loopback_json(404, ['error' => $error]);
}

/** Promete 100 bytes de body, manda 14 y cierra. */
function loopback_cut(): string
{
    return loopback_response(200, [
        ['Content-Type', 'application/json'],
        ['Content-Length', '100'],
        ['Connection', 'close'],
    ], '{"object":"bal');
}

/** BALANCE con los nombres de los headers en minúscula (como HTTP/2) y uno repetido. */
function loopback_lowercase(): string
{
    $body = loopback_encode(Fixtures::BALANCE);
    return loopback_response(200, [
        ['content-type', 'application/json'],
        ['raya-request-id', 'req_loopback'],
        ['x-raya-dup', 'uno'],
        ['x-raya-dup', 'dos'],
        ['content-length', (string) strlen($body)],
        ['connection', 'close'],
    ], $body);
}

function loopback_gzip(): string
{
    $body = gzencode(loopback_encode(Fixtures::BALANCE));
    if ($body === false) {
        fwrite(STDERR, "loopback-server: no pude comprimir el body.\n");
        $body = '';
    }
    return loopback_response(200, [
        ['Content-Type', 'application/json'],
        ['Content-Encoding', 'gzip'],
        ['Raya-Request-Id', 'req_loopback'],
        ['Content-Length', (string) strlen($body)],
        ['Connection', 'close'],
    ], $body);
}
