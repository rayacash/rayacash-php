<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use RayaCash\HttpClient\CurlClient;
use RayaCash\HttpClient\Headers;
use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\Internal\HttpClientManager;
use RayaCash\Internal\Text;
use RayaCash\Internal\Timing;
use RayaCash\Internal\Transport;
use RayaCash\RayaCash;
use RayaCash\RayaResponse;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\TestCase;

/**
 * Lo interno que el resto de los tests reemplaza o no mira: el reloj y las esperas de verdad, el
 * cliente HTTP propio del SDK, lo que muestra var_dump de una respuesta y la versión
 * (test_internals.py e internals.test.ts), y qué es un espacio (el de str.strip() en Python).
 * Node prueba además su señal de timeout y un runtime sin fetch: acá los timeouts son de curl
 * (TransportTest) y ext-curl es obligatoria (ExtensionsTest).
 */
final class InternalsTest extends TestCase
{
    public function testRealSleepAndJitter(): void
    {
        // Los de verdad: setUp() los cambió por los de prueba. Duerme 1 ms de verdad.
        Timing::reset();
        $started = Timing::monotonic();
        Timing::sleep(0);
        Timing::sleep(0.001);
        $slept = Timing::monotonic() - $started;
        self::assertGreaterThanOrEqual(0.001, $slept);
        self::assertLessThan(1.0, $slept);

        $jitters = [];
        for ($i = 0; $i < 100; $i++) {
            $jitters[] = Timing::jitter();
        }
        foreach ($jitters as $jitter) {
            self::assertGreaterThanOrEqual(0.0, $jitter);
            self::assertLessThan(1.0, $jitter);
        }
        self::assertGreaterThan(1, count(array_unique($jitters)), 'El jitter no es al azar.');

        // Node: el reloj real, en segundos unix, y uno que solo avanza (para elapsed).
        self::assertEqualsWithDelta(microtime(true), Timing::now(), 5.0);
        $before = Timing::monotonic();
        self::assertGreaterThanOrEqual($before, Timing::monotonic());
        self::assertEqualsWithDelta((float) hrtime(true) / 1e9, Timing::monotonic(), 5.0);
    }

    public function testHttpClientManagerBuildsRealClientsLazily(): void
    {
        // Sin la guardia de los tests: el propio es un CurlClient (crearlo no abre conexiones).
        // Que nunca sigue un redirect lo mira TransportTest, contra un servidor de verdad.
        Transport::$factory = null;
        $manager = new HttpClientManager();
        $client = $manager->client();
        self::assertInstanceOf(CurlClient::class, $client);
        self::assertSame($client, $manager->client());

        // close() suelta la conexión del propio (como is_closed en Python), cerrar dos veces no
        // falla y la próxima request crea otro.
        $handle = new \ReflectionProperty(CurlClient::class, 'handle');
        $handle->setValue($client, curl_init());
        $manager->close();
        self::assertNull($handle->getValue($client));
        $manager->close();
        $next = $manager->client();
        self::assertInstanceOf(CurlClient::class, $next);
        self::assertNotSame($client, $next);

        // Perezoso: se crea con la primera request, una sola vez.
        $built = 0;
        Transport::$factory = function () use (&$built): HttpClientInterface {
            $built++;
            return $this->api;
        };
        $lazy = new HttpClientManager();
        self::assertSame(0, $built);
        $first = $lazy->client();
        $second = $lazy->client();
        self::assertSame([$this->api, $this->api, 1], [$first, $second, $built]);

        // El que pasaste se usa tal cual y nunca se cierra.
        $yours = new CurlClient();
        $handle->setValue($yours, curl_init());
        $manager = new HttpClientManager($yours);
        self::assertSame($yours, $manager->client());
        $manager->close();
        self::assertInstanceOf(\CurlHandle::class, $handle->getValue($yours));
        self::assertSame($yours, $manager->client());
        self::assertSame(1, $built);
    }

    public function testResponseRepr(): void
    {
        $response = new RayaResponse(
            200,
            new Headers(['Raya-Request-Id' => 'req_x']),
            '{"nombre":"secreto"}',
            (object) ['nombre' => 'secreto'],
            null,
        );
        // El repr de PHP son print_r y var_dump: solo el status y el request id, porque el body
        // puede traer nombres, CUIT y CBU (Node también lo prueba con un body así).
        self::assertSame(
            "RayaCash\\RayaResponse Object\n(\n    [http_status] => 200\n"
            . "    [request_id] => req_x\n)\n",
            print_r($response, true),
        );
        self::assertSame(['http_status' => 200, 'request_id' => 'req_x'], $response->__debugInfo());
        ob_start();
        var_dump($response);
        $dump = (string) ob_get_clean();
        // Sin mirar el formato exacto: con Xdebug en modo develop (el suyo si no se configura
        // otro), var_dump es otro.
        foreach (['http_status', 'int(200)', 'request_id', 'string(5) "req_x"'] as $shown) {
            self::assertStringContainsString($shown, $dump);
        }
        self::assertStringNotContainsString('secreto', $dump);
        self::assertSame('req_x', $response->requestId);

        // Sin Raya-Request-Id (un HTML del edge, por ejemplo), request_id es null.
        $bare = new RayaResponse(502, new Headers(), '', new \stdClass(), 'k');
        self::assertSame(['http_status' => 502, 'request_id' => null], $bare->__debugInfo());
        self::assertNull($bare->requestId);
    }

    /** Node: la versión es la del paquete y va en el User-Agent. */
    public function testTheVersionIsTheOneInRayaCashAndGoesInTheUserAgent(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', RayaCash::VERSION);
        // Una sola línea la define: la que leen scripts/check-version.php y el release.
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/RayaCash.php');
        preg_match_all("/^[ \t]*public const VERSION = '([^']*)';$/m", $source, $lines);
        self::assertSame([RayaCash::VERSION], $lines[1]);

        $this->api->add(200, Fixtures::BALANCE);
        $this->api->client()->balance->retrieve();
        $curl = curl_version();
        $curlVersion = is_array($curl) ? $curl['version'] ?? null : null;
        self::assertIsString($curlVersion);
        self::assertSame(
            sprintf(
                'rayacash-php/%s PHP/%d.%d.%d curl/%s',
                RayaCash::VERSION,
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
                PHP_RELEASE_VERSION,
                $curlVersion,
            ),
            $this->api->last()->header('user-agent'),
        );
    }

    /**
     * PHP: Text::strip() saca de los bordes lo mismo que str.strip() de Python, ni más ni menos.
     * Lo usan la clave, los montos en texto, los ids, api_base, Retry-After y los webhooks. El
     * \s de PCRE no alcanza: no toma U+001C a U+001F, que para Python son espacios.
     */
    public function testStripRemovesWhatPythonStrips(): void
    {
        // [c for c in range(0x10000) if chr(c).isspace()] en Python 3.
        $python = [
            ...range(0x09, 0x0d),
            ...range(0x1c, 0x20),
            0x85,
            0xa0,
            0x1680,
            ...range(0x2000, 0x200a),
            0x2028,
            0x2029,
            0x202f,
            0x205f,
            0x3000,
        ];
        $stripped = [];
        $spaces = '';
        for ($code = 0; $code <= 0xffff; $code++) {
            // El carácter en UTF-8 (sin mbstring: el SDK no la pide).
            $char = json_decode(sprintf('"%su%04x"', chr(92), $code));
            if (!is_string($char)) {
                continue; // la mitad de un par sustituto no es un carácter
            }
            if (Text::strip("{$char}x{$char}") === 'x') {
                $stripped[] = $code;
                $spaces .= $char;
            }
        }
        self::assertSame($python, $stripped);
        self::assertTrue(Text::isBlank($spaces));
        self::assertSame('a b', Text::strip("{$spaces}a b{$spaces}"));
        // Lo que no es UTF-8 válido no se toca y no cuenta como vacío.
        self::assertNull(Text::strip("\xff "));
        self::assertFalse(Text::isBlank("\xff"));
        // Un millón de espacios en un borde tampoco es un error de PCRE (sería null: «no es
        // UTF-8»).
        $many = str_repeat(' ', 1_000_000);
        self::assertSame(['a', 'a', ''], [
            Text::strip("a{$many}"),
            Text::strip("{$many}a"),
            Text::strip($many),
        ]);
    }

    /**
     * PHP: sin JIT (pcre.jit=0, o SELinux o W^X que no lo dejan), Text::strip() sigue siendo
     * lineal. La regex de antes volvía a recorrer un tramo de espacios del medio desde cada
     * espacio: 16 KB tardaban más de un segundo, y los puede mandar cualquiera en el header de un
     * webhook, antes de que se chequee la firma. En otro proceso: pcre.jit no cambia una regex que
     * ya se compiló.
     */
    public function testStripIsLinearWithoutJit(): void
    {
        $code = <<<'PHP'
            <?php
            require AUTOLOAD;
            $text = 'a' . str_repeat(' ', 16384) . 'b';
            $started = hrtime(true);
            $stripped = RayaCash\Internal\Text::strip($text);
            $seconds = (hrtime(true) - $started) / 1e9;
            echo 'jit=', ini_get('pcre.jit'), ' ', $stripped === $text ? 'igual' : 'distinto', ' ',
                $seconds < 0.25 ? 'rápido' : "lento ({$seconds} s)";
            PHP;
        [$status, $output] = self::php(
            ['-d', 'pcre.jit=0', '-d', 'xdebug.mode=off'],
            self::withAutoload($code),
        );
        self::assertSame([0, 'jit=0 igual rápido'], [$status, $output]);
    }

    /**
     * PHP: algunos hostings ponen getenv(), error_log() o curl_version() en disable_functions, y
     * desde PHP 8 llamar a una así es un Error. El SDK sigue andando, con la clave pasada a mano y
     * el log prendido: sin getenv() quedan $_ENV y $_SERVER, sin error_log() la consola no
     * escribe (el logger de setLogger() sí recibe cada línea) y el User-Agent dice curl/unknown.
     * Hasta PHP 8.2, con el código por STDIN no existe STDERR y la consola es error_log(), como
     * en FPM; desde 8.3, STDERR existe igual y la consola escribe ahí.
     */
    public function testRunsWithGetenvErrorLogAndCurlVersionDisabled(): void
    {
        $code = <<<'PHP'
            <?php
            require AUTOLOAD;
            RayaCash\Internal\Transport::$factory = static function (): never {
                throw new RuntimeException('Sin red.');
            };
            final class FakeHttp implements RayaCash\HttpClient\HttpClientInterface
            {
                public ?RayaCash\HttpClient\Request $last = null;

                public function send(
                    RayaCash\HttpClient\Request $request,
                ): RayaCash\HttpClient\Response {
                    $this->last = $request;
                    $json = '{"object":"balance","available":1500,"currency":"ARS"}';
                    return new RayaCash\HttpClient\Response(
                        200,
                        new RayaCash\HttpClient\Headers(),
                        $json,
                    );
                }
            }
            RayaCash\RayaCash::setLog('info');
            $http = new FakeHttp();
            $client = new RayaCash\RayaClient('raya_sk_live_x', ['http_client' => $http]);
            echo $client->balance->retrieve()->available, ' ', $http->last?->header('User-Agent'),
                "\n";
            PHP;
        [$status, $output] = self::php(
            ['-d', 'disable_functions=getenv,error_log,curl_version', '-d', 'xdebug.mode=off'],
            self::withAutoload($code),
        );
        self::assertSame(0, $status, $output);
        $lines = explode("\n", rtrim($output, "\n"));
        self::assertMatchesRegularExpression(
            '/\A1500 rayacash-php\/\S+ PHP\/\S+ curl\/unknown\z/',
            $lines[0],
        );
        // La request y la respuesta, en STDERR desde 8.3; antes, en error_log(), que no está.
        self::assertCount(PHP_VERSION_ID >= 80300 ? 3 : 1, $lines, $output);
    }

    /** El código, con el autoload del repo en lugar de AUTOLOAD. */
    private static function withAutoload(string $code): string
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        return str_replace('require AUTOLOAD;', "require {$autoload};", $code);
    }
}
