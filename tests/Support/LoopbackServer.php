<?php

declare(strict_types=1);

namespace RayaCash\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Un servidor HTTP de verdad en 127.0.0.1 (tests/Support/loopback-server.php, en otro proceso)
 * para TransportTest: el CurlClient del SDK le habla por un socket, sin salir de loopback, como
 * el servidor de transport.test.ts en Node. MockApi no puede mostrar lo que pone curl
 * (Content-Length, Expect, redirects, cortes y timeouts de verdad); este sí.
 *
 * ```php
 * $server = LoopbackServer::start();
 * $client = new RayaClient(Fixtures::TEST_KEY, [
 *     'api_base' => $server->base('json-200-BALANCE'), // qué responde: ver loopback-server.php
 *     'http_client' => new CurlClient(),
 * ]);
 * $client->balance->retrieve();
 * [$request] = $server->received(1); // método, ruta, headers y body tal como llegaron
 * $server->stop();                   // en tearDown(): falla si el servidor avisó algo
 * ```
 *
 * Con LoopbackServer::start(tls: true) habla HTTPS, con un certificado propio para 127.0.0.1
 * recién hecho: curl lo rechaza (curl 60) salvo que se lo pases con
 * `new CurlClient([CURLOPT_CAINFO => $server->certificate])`.
 *
 * @phpstan-type Received array{
 *     id: string,
 *     scenario: string,
 *     method: string,
 *     target: string,
 *     path: string,
 *     protocol: string,
 *     headers: array<string, string>,
 *     body: string|null,
 *     length: int,
 *     sha256: string,
 * }
 */
final class LoopbackServer
{
    /** Segundos para que el servidor diga en qué puerto escucha. */
    private const START_TIMEOUT = 5.0;

    /** Segundos de espera máxima en received() y en stop(). */
    private const WAIT = 2.0;

    /** @var list<Received> Lo anotado que todavía no devolvió received(). */
    private array $pending = [];

    /** Lo leído del STDOUT del servidor que todavía no completó una línea. */
    private string $stdout = '';

    private int $count = 0;

    private bool $stopped = false;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes 0: su STDIN, 1: su STDOUT, 2: su STDERR.
     * @param string|null $certificate Con HTTPS, el archivo de su certificado (para curl).
     * @param list<string> $files Lo que borra stop(): el certificado y su clave.
     */
    private function __construct(
        private readonly mixed $process,
        private readonly array $pipes,
        public readonly int $port,
        public readonly ?string $certificate,
        private readonly array $files,
    ) {}

    /**
     * Arranca el servidor y espera a que diga en qué puerto escucha. Con $tls, habla HTTPS con un
     * certificado autofirmado para 127.0.0.1, hecho en el momento.
     */
    public static function start(bool $tls = false): self
    {
        $files = $tls ? self::selfSignedCertificate() : [];
        $script = __DIR__ . '/loopback-server.php';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script, ...$files],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            self::remove($files);
            Assert::fail('No pude arrancar tests/Support/loopback-server.php.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $line = null;
        $output = '';
        $deadline = hrtime(true) + (int) (self::START_TIMEOUT * 1e9);
        while ($line === null && !feof($pipes[1]) && hrtime(true) < $deadline) {
            self::waitForData($pipes[1], $deadline);
            $output .= (string) fread($pipes[1], 8192);
            $newline = strpos($output, "\n");
            if ($newline !== false) {
                $line = substr($output, 0, $newline);
                $output = substr($output, $newline + 1);
            }
        }
        if ($line === null || preg_match('/\APORT ([0-9]+)\z/', $line, $match) !== 1) {
            $stderr = (string) stream_get_contents($pipes[2]);
            proc_terminate($process, 9);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            self::remove($files);
            Assert::fail("El servidor de loopback no dijo su puerto:\n{$output}{$stderr}");
        }
        $server = new self($process, $pipes, (int) $match[1], $files[0] ?? null, $files);
        $server->stdout = $output;
        return $server;
    }

    /**
     * La api_base para un escenario de loopback-server.php, con un id nuevo (así cut-once vuelve
     * a cortar en cada llamada): http://127.0.0.1:<puerto>/<escenario>-<n> (https:// con TLS).
     */
    public function base(string $scenario): string
    {
        $this->count++;
        $scheme = $this->certificate === null ? 'http' : 'https';
        return "{$scheme}://127.0.0.1:{$this->port}/{$scenario}-{$this->count}";
    }

    /**
     * Las requests que llegaron desde la última llamada, en orden, esperando hasta 2 s a que sean
     * al menos $atLeast. Cada una se anota antes de responderla: cuando el SDK vuelve, ya están.
     *
     * @return list<Received>
     */
    public function received(int $atLeast = 0): array
    {
        $deadline = hrtime(true) + (int) (self::WAIT * 1e9);
        $this->readRecords();
        while (count($this->pending) < $atLeast && !feof($this->pipes[1])) {
            if (!self::waitForData($this->pipes[1], $deadline)) {
                break;
            }
            $this->readRecords();
        }
        $received = $this->pending;
        $this->pending = [];
        return $received;
    }

    /**
     * Lo frena (le cierra STDIN y termina solo) y falla si escribió algo en STDERR: un aviso de
     * PHP en el servidor es un error del test. Se puede llamar más de una vez.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        fclose($this->pipes[0]);
        $deadline = hrtime(true) + (int) (self::WAIT * 1e9);
        while (!feof($this->pipes[1]) && self::waitForData($this->pipes[1], $deadline)) {
            fread($this->pipes[1], 65536);
        }
        $exited = feof($this->pipes[1]);
        if (!$exited) {
            proc_terminate($this->process, 9);
        }
        $stderr = (string) stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $status = proc_close($this->process);
        self::remove($this->files);
        Assert::assertSame('', $stderr, 'El servidor de loopback escribió en STDERR.');
        Assert::assertTrue($exited, 'El servidor de loopback no terminó al cerrarle STDIN.');
        Assert::assertSame(0, $status, 'El servidor de loopback terminó con error.');
    }

    /**
     * Un certificado autofirmado para 127.0.0.1 (en subjectAltName) y su clave privada, en dos
     * archivos temporales: [certificado, clave].
     *
     * @return list<string>
     */
    private static function selfSignedCertificate(): array
    {
        $config = self::temporaryFile(
            "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = IP:127.0.0.1\n",
        );
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext'];
            $key = openssl_pkey_new(
                ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] + $options,
            );
            Assert::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key, 'openssl_pkey_new()');
            $signer = $key; // openssl_csr_new() la recibe por referencia
            $request = openssl_csr_new(['commonName' => '127.0.0.1'], $signer, $options);
            Assert::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $request);
            $signed = openssl_csr_sign($request, null, $key, 1, $options, random_int(1, 2 ** 30));
            Assert::assertInstanceOf(\OpenSSLCertificate::class, $signed, 'openssl_csr_sign()');
            Assert::assertTrue(openssl_x509_export($signed, $certificate));
            Assert::assertTrue(openssl_pkey_export($key, $privateKey, null, $options));
            Assert::assertIsString($certificate);
            Assert::assertIsString($privateKey);
        } finally {
            self::remove([$config]);
        }
        return [self::temporaryFile($certificate), self::temporaryFile($privateKey)];
    }

    /** Un archivo temporal con $content: lo borra remove(). */
    private static function temporaryFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rayacash-loopback-');
        Assert::assertIsString($file);
        Assert::assertNotFalse(file_put_contents($file, $content));
        return $file;
    }

    /** @param list<string> $files */
    private static function remove(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Espera hasta $deadline (hrtime) a que haya algo para leer en $pipe.
     *
     * @param resource $pipe
     */
    private static function waitForData($pipe, int $deadline): bool
    {
        $left = $deadline - hrtime(true);
        if ($left <= 0) {
            return false;
        }
        $read = [$pipe];
        $write = null;
        $except = null;
        $micro = intdiv($left, 1000);
        return stream_select($read, $write, $except, intdiv($micro, 1000000), $micro % 1000000) > 0;
    }

    /** Pasa a $pending cada línea completa que el servidor ya escribió. */
    private function readRecords(): void
    {
        while (($chunk = fread($this->pipes[1], 65536)) !== false && $chunk !== '') {
            $this->stdout .= $chunk;
        }
        while (($newline = strpos($this->stdout, "\n")) !== false) {
            $line = substr($this->stdout, 0, $newline);
            $this->stdout = substr($this->stdout, $newline + 1);
            $this->pending[] = self::record($line);
        }
    }

    /** @return Received */
    private static function record(string $line): array
    {
        $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($data, "El servidor de loopback anotó algo raro: {$line}");
        $headers = [];
        $pairs = $data['headers'] ?? null;
        Assert::assertIsArray($pairs);
        foreach ($pairs as $pair) {
            Assert::assertIsArray($pair);
            [$name, $value] = $pair + [null, null];
            Assert::assertIsString($name);
            Assert::assertIsString($value);
            $name = strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
            // Como Headers del SDK: un header repetido junta sus valores con ", ".
            $headers[$name] = isset($headers[$name]) ? "{$headers[$name]}, {$value}" : $value;
        }
        $body = $data['body'] ?? null;
        if ($body !== null) {
            Assert::assertIsString($body);
            $body = base64_decode($body, true);
            Assert::assertIsString($body);
        }
        $length = $data['length'] ?? null;
        Assert::assertIsInt($length);
        return [
            'id' => self::text($data, 'id'),
            'scenario' => self::text($data, 'scenario'),
            'method' => self::text($data, 'method'),
            'target' => self::text($data, 'target'),
            'path' => self::text($data, 'path'),
            'protocol' => self::text($data, 'protocol'),
            'headers' => $headers,
            'body' => $body,
            'length' => $length,
            'sha256' => self::text($data, 'sha256'),
        ];
    }

    /** @param array<mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        Assert::assertIsString($value, "El servidor de loopback no anotó {$key}.");
        return $value;
    }
}
