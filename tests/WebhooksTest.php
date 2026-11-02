<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Event;
use RayaCash\Event\Data;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Internal\Timing;
use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\RayaObject;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Webhook;
use RayaCash\WebhookSignature;

/**
 * La firma de los webhooks y constructEvent (test_webhooks.py): los vectores publicados, el
 * parseo tolerante del header, cada mensaje y la tolerancia simétrica. El reloj del SDK queda
 * congelado en el t del vector: setUp() fija $this->now, que es lo que lee Timing::$now.
 */
final class WebhooksTest extends TestCase
{
    // T y HEADER, como en test_webhooks.py. BODY y SECRET son WEBHOOK_TEST_BODY y WEBHOOK_SECRET.
    private const T = Fixtures::WEBHOOK_TEST_TIMESTAMP;
    private const BODY = Fixtures::WEBHOOK_TEST_BODY;
    private const SECRET = Fixtures::WEBHOOK_SECRET;
    private const HEADER = 't=' . self::T . ',v1=' . Fixtures::WEBHOOK_SIGNATURE;

    // Los mensajes enteros: Python mira un pedazo con match=, acá se compara el texto completo.
    private const MISSING_HEADER = 'Falta el header Raya-Signature.';
    private const NO_TIMESTAMP
        = 'No pudimos leer t (el momento del envío) del header Raya-Signature.';
    private const NO_SIGNATURES = 'El header Raya-Signature no trae firmas v1.';
    private const MISMATCH = 'La firma no coincide: revisá el secreto (whsec_…) y que uses el '
        . 'body crudo, sin parsear.';
    private const CLOCK = 'El momento de la firma (t=%s) está a más de %s s de tu reloj. Si es '
        . 'un reintento, se firma con un t nuevo: revisá la hora del servidor.';
    private const NO_SECRET
        = 'Falta el secreto del webhook (whsec_…): está en Developers › Webhooks.';
    private const BAD_TOLERANCE = 'tolerance tiene que ser un número de segundos, 0 o más.';
    private const NOT_SECONDS = 'tolerance tiene que ser un número de segundos o null.';
    private const BAD_TIMESTAMP = 'timestamp tiene que ser un int de segundos unix.';
    private const NOT_AN_OBJECT = 'El body del webhook no es un objeto JSON.';
    private const NOT_THE_RAW_BODY = 'payload tiene que ser el body crudo (string), sin parsear: '
        . "en PHP plano, file_get_contents('php://input'); en Laravel y Symfony, "
        . '$request->getContent(); con PSR-7, (string) $request->getBody(). Llegó %s.';
    private const NOT_A_HEADER = 'header tiene que ser el valor del header Raya-Signature, un '
        . "string: con PSR-7, \$request->getHeaderLine('Raya-Signature'). Llegó %s.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (float) self::T; // el reloj, congelado en el t del vector (frozen_clock)
    }

    public function testPublishedVectors(): void
    {
        self::assertSame(
            Fixtures::WEBHOOK_SIGNATURE,
            WebhookSignature::computeSignature(self::BODY, self::SECRET, self::T),
        );
        self::assertSame(
            Fixtures::WEBHOOK_OLD_SIGNATURE,
            WebhookSignature::computeSignature(self::BODY, Fixtures::WEBHOOK_OLD_SECRET, self::T),
        );
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET));
        // Los vectores del contrato común de los SDKs de Raya, tal cual (los de raya-api,
        // webhooks/tests_crypto.py): todos los SDKs tienen que dar exactamente esto.
        $body = '{"id":"evt_test","object":"event","type":"webhook.test"}';
        self::assertSame(
            '2d8099cd1fe615acd8a181903c2478d2526fc47c784558a9c4e512619c4e60d8',
            WebhookSignature::computeSignature($body, 'whsec_test_5f2b8c1e', 1700000000),
        );
        self::assertSame(
            '8e449e40286c1e331a636dd9e38ab4a548c3c9a09733fa05205d7a10623ecc45',
            WebhookSignature::computeSignature($body, 'whsec_old_0a9d', 1700000000),
        );
    }

    public function testTheApiMdVectorWithARealClock(): void
    {
        $this->now = 2_000_000_000.0;
        // El t del vector es viejo: para probarlo, la doc pide saltear el chequeo de los 5 minutos.
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET, null));
        self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, self::HEADER, self::SECRET),
            'de tu reloj',
        );
    }

    public function testRotationSendsTwoSignatures(): void
    {
        $header = self::HEADER . ',v1=' . Fixtures::WEBHOOK_OLD_SIGNATURE;
        self::assertTrue(self::verify(self::BODY, $header, self::SECRET));
        self::assertTrue(self::verify(self::BODY, $header, Fixtures::WEBHOOK_OLD_SECRET));
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, $header, 'whsec_otro'),
            'no coincide',
        );
        self::assertSame(self::MISMATCH, $error->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function lenientHeaders(): iterable
    {
        $t = self::T;
        $signature = Fixtures::WEBHOOK_SIGNATURE;
        yield 'espacio después de la coma' => ["t={$t}, v1={$signature}"];
        yield 'otro orden, con espacios en los bordes' => [" v1={$signature} , t={$t} "];
        yield 'esquemas desconocidos y partes sin =' => ["t={$t},v0=abc,v1={$signature},extra"];
        yield 'firma en mayúsculas' => ["t={$t},v1=" . strtoupper($signature)];
        yield 'gana el primer t válido; las v1 que no son hex no cuentan' => [
            "t=,t={$t},v1=no-es-hex,v1={$signature}",
        ];
        // De Node: los ceros de adelante no se firman (el t es el número, como el int() de Python).
        yield 'ceros adelante' => ["t=000{$t},v1={$signature}"];
        // De PHP, con lo mismo que hace Python: el primer t gana aunque después venga otro válido;
        // la clave y el valor se recortan aparte; los 20 caracteres cuentan los ceros; un tab es
        // un espacio más; y como el header llega como bytes, una parte que no es UTF-8 se saltea
        // como cualquier parte sin «=».
        yield 'un segundo t válido no cuenta' => ["t={$t},t=" . ($t + 1) . ",v1={$signature}"];
        yield 'espacios alrededor del =' => ["t = {$t} , v1 = {$signature}"];
        yield 't de 20 caracteres' => ['t=' . str_repeat('0', 10) . "{$t},v1={$signature}"];
        yield 'tab' => ["t={$t},\tv1={$signature}"];
        yield 'una parte que no es UTF-8' => ["t={$t},v1={$signature},\xff"];
    }

    #[DataProvider('lenientHeaders')]
    public function testLenientHeaderParsing(string $header): void
    {
        self::assertTrue(self::verify(self::BODY, $header, self::SECRET));
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function invalidHeaders(): iterable
    {
        $t = self::T;
        $signature = Fixtures::WEBHOOK_SIGNATURE;
        // Node suma undefined: en PHP no existe (un header que no vino llega como null).
        yield 'null' => [null, self::MISSING_HEADER];
        yield 'vacío' => ['', self::MISSING_HEADER];
        yield 'solo espacios' => ['   ', self::MISSING_HEADER];
        yield 'sin t' => ["v1={$signature}", self::NO_TIMESTAMP];
        yield 't no numérico' => ["t=abc,v1={$signature}", self::NO_TIMESTAMP];
        yield 't con dígitos de ancho completo' => ["t=１７００,v1={$signature}", self::NO_TIMESTAMP];
        yield 't de 30 dígitos' => [
            't=' . str_repeat('9', 30) . ",v1={$signature}",
            self::NO_TIMESTAMP,
        ];
        yield 'sin v1' => ["t={$t}", self::NO_SIGNATURES];
        yield 'v1 vacía' => ["t={$t},v1=", self::NO_SIGNATURES];
        yield 'otra firma' => ["t={$t},v1=" . str_repeat('0', 64), self::MISMATCH];
        yield 'otro t' => ['t=' . ($t + 1) . ",v1={$signature}", self::MISMATCH];
        // De PHP: 21 caracteres (con los ceros), un t negativo, un byte que no es UTF-8 en el t,
        // el esquema en mayúscula y una firma de 63 caracteres.
        yield 't de 21 caracteres' => [
            't=' . str_repeat('0', 11) . "{$t},v1={$signature}",
            self::NO_TIMESTAMP,
        ];
        yield 't negativo' => ["t=-{$t},v1={$signature}", self::NO_TIMESTAMP];
        yield 't con un byte que no es UTF-8' => ["t={$t}\xff,v1={$signature}", self::NO_TIMESTAMP];
        yield 'V1 en mayúscula' => ["t={$t},V1={$signature}", self::NO_SIGNATURES];
        yield 'firma de 63 caracteres' => [
            "t={$t},v1=" . substr($signature, 1),
            self::NO_SIGNATURES,
        ];
    }

    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaders(?string $header, string $message): void
    {
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, $header, self::SECRET),
        );
        self::assertSame($message, $error->getMessage());
        self::assertSame($header, $error->getSigHeader());
        self::assertSame(self::BODY, $error->getHttpBody());
    }

    public function testTamperedBody(): void
    {
        $tampered = str_replace('webhook.test', 'payment.succeeded', self::BODY);
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify($tampered, self::HEADER, self::SECRET),
            'body crudo',
        );
        self::assertSame(self::MISMATCH, $error->getMessage());
        self::assertSame($tampered, $error->getHttpBody());
        // Como en Python: un error de firma no trae código ni status HTTP.
        self::assertNull($error->getErrorCode());
        self::assertNull($error->getHttpStatus());
    }

    /** @return iterable<string, array{int}> */
    public static function skewsInside(): iterable
    {
        foreach ([-300, 300, -1, 1] as $skew) {
            yield "{$skew} s" => [$skew];
        }
    }

    #[DataProvider('skewsInside')]
    public function testToleranceInside(int $skew): void
    {
        $this->now = (float) (self::T + $skew);
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET));
    }

    /** @return iterable<string, array{int|float}> */
    public static function skewsOutside(): iterable
    {
        foreach ([-301, 301, 3600] as $skew) {
            yield "{$skew} s" => [$skew];
        }
        // De PHP: el reloj tiene fracción (microtime), como time.time() en Python: medio segundo
        // de más ya queda afuera.
        yield '300.5 s' => [300.5];
        yield '-300.5 s' => [-300.5];
    }

    #[DataProvider('skewsOutside')]
    public function testToleranceIsSymmetric(int|float $skew): void
    {
        $this->now = (float) (self::T + $skew);
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, self::HEADER, self::SECRET),
            '300 s',
        );
        self::assertSame(sprintf(self::CLOCK, self::T, '300'), $error->getMessage());
        self::assertSame(self::HEADER, $error->getSigHeader());
        self::assertSame(self::BODY, $error->getHttpBody());
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET, null));
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET, 0));
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET, 4000));
        // De PHP: 0.0 también la apaga, y INF nunca se supera.
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET, 0.0));
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET, INF));
    }

    public function testPayloadForms(): void
    {
        self::assertTrue(self::verify(self::BODY, self::HEADER, self::SECRET));
        // Python prueba también bytearray y memoryview. En PHP el body es un string, o un
        // \Stringable como el body de PSR-7 ($request->getBody()), que entra directo.
        $stream = self::stream(self::BODY);
        self::assertTrue(self::verify($stream, self::HEADER, self::SECRET));
        self::assertSame(
            Fixtures::WEBHOOK_SIGNATURE,
            WebhookSignature::computeSignature($stream, self::SECRET, self::T),
        );
        self::assertSame(self::HEADER, WebhookSignature::generateHeader($stream, self::SECRET));
        $event = Webhook::constructEvent($stream, self::HEADER, self::SECRET);
        self::assertSame('webhook.test', $event->type);
        // Lo que haría alguien que ya parseó el JSON (json_decode($body, true)).
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => self::verify(['id' => 'evt_test'], self::HEADER, self::SECRET),
            'body crudo',
        );
        self::assertSame(sprintf(self::NOT_THE_RAW_BODY, 'array'), $error->getMessage());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function notRawBodies(): iterable
    {
        yield 'el JSON ya parseado, como objeto' => [json_decode(self::BODY), 'stdClass'];
        yield 'false (file_get_contents falló)' => [false, 'bool'];
        yield 'null' => [null, 'null'];
        yield 'un int' => [1700000000, 'int'];
        yield 'un objeto sin __toString' => [new \ArrayObject(['id' => 'evt_test']), 'ArrayObject'];
    }

    /**
     * De PHP: lo que suele llegar en vez del body crudo falla igual en todas las entradas, y el
     * mensaje dice qué llegó.
     */
    #[DataProvider('notRawBodies')]
    public function testPayloadsThatAreNotTheRawBody(mixed $payload, string $type): void
    {
        $client = $this->api->client();
        $calls = [
            'verifyHeader' => static fn(): mixed => self::verify(
                $payload,
                self::HEADER,
                self::SECRET,
            ),
            'computeSignature' => static fn(): mixed => WebhookSignature::computeSignature(
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                $payload,
                self::SECRET,
                self::T,
            ),
            'generateHeader' => static fn(): mixed => WebhookSignature::generateHeader(
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                $payload,
                self::SECRET,
            ),
            'Webhook::constructEvent' => static fn(): mixed => Webhook::constructEvent(
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                $payload,
                self::HEADER,
                self::SECRET,
            ),
            '$client->constructEvent' => static fn(): mixed => $client->constructEvent(
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                $payload,
                self::HEADER,
                self::SECRET,
            ),
        ];
        foreach ($calls as $name => $call) {
            $error = self::assertThrows(\TypeError::class, $call);
            self::assertSame(
                sprintf(self::NOT_THE_RAW_BODY, $type),
                $error->getMessage(),
                $name,
            );
        }
        self::assertSame([], $this->api->requests);
    }

    /**
     * @return iterable<string, array{
     *     array<string, mixed>,
     *     class-string<\Throwable>,
     *     non-empty-string,
     * }>
     */
    public static function programmingErrors(): iterable
    {
        yield 'secret vacío' => [['secret' => ''], \ValueError::class, self::NO_SECRET];
        // Python da TypeError. En PHP una config sin definir llega como null: es un secreto que
        // falta (diseño), con el mismo error que uno vacío.
        yield 'secret null' => [['secret' => null], \ValueError::class, self::NO_SECRET];
        yield 'tolerance negativa' => [
            ['tolerance' => -1],
            \ValueError::class,
            self::BAD_TOLERANCE,
        ];
        // De Node.
        yield 'tolerance NAN' => [['tolerance' => NAN], \ValueError::class, self::BAD_TOLERANCE];
        // El mensaje es el de Node (Python dice None). El parámetro es mixed: sin strict_types,
        // PHP convertiría false en 0, que apaga el chequeo del reloj (ClientTest).
        yield 'tolerance string' => [['tolerance' => '300'], \TypeError::class, self::NOT_SECONDS];
        // El orden de Python (_webhook.py): primero el tipo y después el rango, así que un '-1'
        // es TypeError, no ValueError.
        yield 'tolerance string negativa' => [
            ['tolerance' => '-1'],
            \TypeError::class,
            self::NOT_SECONDS,
        ];
        // De PHP: los bool, que en Python también son TypeError.
        yield 'tolerance true' => [['tolerance' => true], \TypeError::class, self::NOT_SECONDS];
        yield 'tolerance false' => [['tolerance' => false], \TypeError::class, self::NOT_SECONDS];
        // Python pasa bytes. En PHP, lo que da getHeader() de PSR-7: una lista de strings.
        yield 'header array' => [
            ['header' => ['t=1,v1=x']],
            \TypeError::class,
            sprintf(self::NOT_A_HEADER, 'array'),
        ];
        // De PHP: un secreto en blanco, uno que no es string y un header int.
        yield 'secret en blanco' => [['secret' => " \t "], \ValueError::class, self::NO_SECRET];
        yield 'secret int' => [
            ['secret' => 123],
            \TypeError::class,
            'RayaCash\WebhookSignature::verifyHeader(): Argument #3 ($secret) must be of type '
                . '?string, int given',
        ];
        yield 'header int' => [
            ['header' => self::T],
            \TypeError::class,
            sprintf(self::NOT_A_HEADER, 'int'),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param class-string<\Throwable> $class
     * @param non-empty-string $message
     */
    #[DataProvider('programmingErrors')]
    public function testProgrammingErrors(array $overrides, string $class, string $message): void
    {
        $arguments = [
            'header' => self::HEADER,
            'secret' => self::SECRET,
            'tolerance' => 300,
            ...$overrides,
        ];
        $error = self::thrown(static fn() => self::verify(self::BODY, ...$arguments));
        self::assertSame($class, $error::class); // la clase exacta, como en Node
        // Los errores del motor siguen con «, called in …».
        self::assertStringStartsWith($message, $error->getMessage());
    }

    public function testConstructEvent(): void
    {
        $body = json_encode(Fixtures::EVENT_PAYMENT_SUCCEEDED, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, self::SECRET);
        $signature = WebhookSignature::computeSignature($body, self::SECRET, self::T);
        self::assertSame('t=' . self::T . ",v1={$signature}", $header);
        $event = Webhook::constructEvent($body, $header, self::SECRET);
        self::assertSame(Event::class, $event::class);
        self::assertSame('evt_4mQ8sT2vX7bN1kR5wZ9cH3jL', $event->id);
        self::assertSame('payment.succeeded', $event->type);
        self::assertSame(Data::class, $event->data::class);
        $payment = $event->data->object;
        self::assertSame(Payment::class, $payment::class);
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame('succeeded', $payment->status);
        // Viene de un webhook, no de una request: no hay respuesta HTTP.
        self::assertNull($event->getLastResponse());
    }

    public function testConstructEventTestAndUnknownTypes(): void
    {
        $event = Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET);
        self::assertSame('webhook.test', $event->type);
        $body = json_encode(
            [
                'id' => 'evt_x',
                'object' => 'event',
                'type' => 'algo.nuevo',
                'data' => ['object' => ['object' => 'nuevo']],
            ],
            JSON_THROW_ON_ERROR,
        );
        $event = Webhook::constructEvent(
            $body,
            WebhookSignature::generateHeader($body, self::SECRET),
            self::SECRET,
        );
        self::assertSame('algo.nuevo', $event['type']); // un tipo que el SDK todavía no conoce
        self::assertSame(RayaObject::class, $event->data->object::class);
    }

    public function testConstructEventRejectsBadSignaturesBeforeParsing(): void
    {
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => Webhook::constructEvent('no es json', self::HEADER, self::SECRET),
        );
        self::assertSame(self::MISMATCH, $error->getMessage());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function notJsonObjects(): iterable
    {
        yield 'una lista' => ['[1, 2]', false];
        // De Node: un JSON roto pero bien firmado (en Python sale el JSONDecodeError de json, que
        // también es un ValueError).
        yield 'no es JSON' => ['no es json', true];
        // De PHP: otros JSON que no son un objeto, un body vacío y UTF-8 inválido.
        yield 'null' => ['null', false];
        yield 'un string' => ['"evt_test"', false];
        yield 'vacío' => ['', true];
        yield 'UTF-8 inválido' => ["{\"id\":\"evt_\xff\"}", true];
    }

    #[DataProvider('notJsonObjects')]
    public function testConstructEventNeedsAJsonObject(string $body, bool $invalidJson): void
    {
        $header = WebhookSignature::generateHeader($body, self::SECRET);
        $error = self::assertThrows(
            \ValueError::class,
            static fn() => Webhook::constructEvent($body, $header, self::SECRET),
            'objeto JSON',
        );
        self::assertSame(self::NOT_AN_OBJECT, $error->getMessage());
        if ($invalidJson) {
            // El error de json_decode queda como previous.
            self::assertInstanceOf(\JsonException::class, $error->getPrevious());
        } else {
            self::assertNull($error->getPrevious());
        }
    }

    public function testGenerateHeaderWithExplicitTimestamp(): void
    {
        $header = WebhookSignature::generateHeader(self::BODY, self::SECRET, timestamp: self::T);
        self::assertSame(self::HEADER, $header);
        // 0 vale (solo un timestamp negativo falla, como en Python): la firma sale de su hmac.
        self::assertSame(
            't=0,v1=56b086beaf89289447ab2bc4dd540097ed57c82238984a7eb635933812c4d23a',
            WebhookSignature::generateHeader(self::BODY, self::SECRET, timestamp: 0),
        );
        self::assertThrows(
            \ValueError::class,
            static fn() => WebhookSignature::generateHeader(
                self::BODY,
                self::SECRET,
                timestamp: -1,
            ),
            self::BAD_TIMESTAMP,
        );
        // Python da ValueError también con True y 1.5 (y Node, RangeError con "1700000000"). En
        // PHP el ?int del parámetro los rechaza al entrar: TypeError, con strict_types.
        foreach ([true, 1.5, '1700000000'] as $bad) {
            self::assertThrows(
                \TypeError::class,
                static fn() => WebhookSignature::generateHeader(
                    self::BODY,
                    self::SECRET,
                    // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                    timestamp: $bad,
                ),
                'Argument #3 ($timestamp) must be of type ?int',
            );
        }
        // Node rechaza 2 ** 60 porque en JavaScript no es un entero exacto. En PHP sí lo es: se
        // firma tal cual (la firma sale del hmac de Python).
        self::assertSame(
            't=1152921504606846976,'
                . 'v1=ed1f7aa11776e80c043fffc3b2f0fe09d9340e1cdc91c66e6aa71976b6b61605',
            WebhookSignature::generateHeader(self::BODY, self::SECRET, timestamp: 2 ** 60),
        );
        // Node pide { timestamp }; en PHP el tercer argumento, posicional, es el timestamp.
        self::assertSame(
            self::HEADER,
            WebhookSignature::generateHeader(self::BODY, self::SECRET, self::T),
        );
    }

    /**
     * De PHP: t llega hasta 20 dígitos, más que PHP_INT_MAX (19). Se lee y se firma como texto
     * sin los ceros de adelante, como el int() de Python y el BigInt de Node. Las firmas
     * esperadas salen del hmac de Python.
     */
    public function testTimestampsOfUpTo20Digits(): void
    {
        $t = '99999999999999999999';
        $signature = 'ace7147ac811a81c432752c904c129fa9909189753e110db17c19a2f64088156';
        self::assertSame(
            $signature,
            WebhookSignature::computeSignature(self::BODY, self::SECRET, $t),
        );
        $header = "t={$t},v1={$signature}";
        self::assertTrue(self::verify(self::BODY, $header, self::SECRET, null));
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, $header, self::SECRET),
        );
        self::assertSame(sprintf(self::CLOCK, $t, '300'), $error->getMessage());

        // Los ceros de adelante no se firman, y el mensaje muestra el t sin ellos.
        self::assertSame(
            Fixtures::WEBHOOK_SIGNATURE,
            WebhookSignature::computeSignature(self::BODY, self::SECRET, '0001700000000'),
        );
        self::assertSame(
            Fixtures::WEBHOOK_SIGNATURE,
            WebhookSignature::computeSignature(self::BODY, self::SECRET, (string) self::T),
        );
        $this->now = (float) (self::T + 301);
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(
                self::BODY,
                't=000' . self::T . ',v1=' . Fixtures::WEBHOOK_SIGNATURE,
                self::SECRET,
            ),
        );
        self::assertSame(sprintf(self::CLOCK, self::T, '300'), $error->getMessage());

        // Los bordes del int: PHP_INT_MAX, y 0 (también con 20 ceros).
        self::assertSame(
            '8a16722cf1f5ce670fb0e84bc2858e2b5b9f80c889cefe9c7be9eb12c4ee34d0',
            WebhookSignature::computeSignature(self::BODY, self::SECRET, PHP_INT_MAX),
        );
        $zero = '56b086beaf89289447ab2bc4dd540097ed57c82238984a7eb635933812c4d23a';
        self::assertSame($zero, WebhookSignature::computeSignature(self::BODY, self::SECRET, 0));
        self::assertSame(
            $zero,
            WebhookSignature::computeSignature(self::BODY, self::SECRET, str_repeat('0', 20)),
        );
    }

    /** @return iterable<string, array{int|string}> */
    public static function invalidTimestamps(): iterable
    {
        yield '-1' => [-1];
        yield '"-1"' => ['-1'];
        yield 'vacío' => [''];
        yield 'abc' => ['abc'];
        yield '1.5' => ['1.5'];
        yield '1e9' => ['1e9'];
        yield 'con un espacio adelante' => [' 1700000000'];
        yield 'con un salto de línea al final' => ["1700000000\n"]; // \z, nunca $
        yield '21 dígitos' => ['1' . str_repeat('0', 20)];
        yield 'dígitos de ancho completo' => ['１７００'];
    }

    /** Como checkTimestamp de Node: computeSignature no firma cualquier cosa como t. */
    #[DataProvider('invalidTimestamps')]
    public function testComputeSignatureRejectsInvalidTimestamps(int|string $timestamp): void
    {
        self::assertThrows(
            \ValueError::class,
            static fn() => WebhookSignature::computeSignature(self::BODY, self::SECRET, $timestamp),
            self::BAD_TIMESTAMP,
        );
    }

    /** De PHP: sin timestamp, generateHeader usa el reloj del SDK en segundos enteros. */
    public function testGenerateHeaderUsesTheClock(): void
    {
        // Trunca, como int(_now()) en Python.
        $this->now = self::T + 0.9;
        self::assertSame(self::HEADER, WebhookSignature::generateHeader(self::BODY, self::SECRET));
        // Con el reloj de verdad (Timing sin reemplazar): el header recién hecho pasa con la
        // tolerancia por defecto, y el del vector (de 2023) no.
        Timing::$now = null;
        $header = WebhookSignature::generateHeader(self::BODY, self::SECRET);
        self::assertMatchesRegularExpression('/\At=[0-9]+,v1=[0-9a-f]{64}\z/', $header);
        [$timestamp] = explode(',', substr($header, 2), 2);
        self::assertEqualsWithDelta(time(), (int) $timestamp, 5);
        self::assertTrue(self::verify(self::BODY, $header, self::SECRET));
        self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, self::HEADER, self::SECRET),
            'de tu reloj',
        );
        // Y constructEvent pasa la tolerancia: null la apaga.
        $event = Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET, null);
        self::assertSame('webhook.test', $event->type);
    }

    /**
     * De PHP: un body de PSR-7 sobre php://input que no se puede rebobinar da su contenido una
     * sola vez. El SDK lo lee una vez y usa ese string para la firma y para el JSON.
     */
    public function testAStreamIsReadOnce(): void
    {
        $client = $this->api->client();
        $calls = [
            'verifyHeader' => static fn(\Stringable $payload): mixed => self::verify(
                $payload,
                self::HEADER,
                self::SECRET,
            ),
            'Webhook::constructEvent' => static fn(\Stringable $payload): mixed
                => Webhook::constructEvent($payload, self::HEADER, self::SECRET),
            '$client->constructEvent' => static fn(\Stringable $payload): mixed
                => $client->constructEvent($payload, self::HEADER, self::SECRET),
        ];
        foreach ($calls as $name => $call) {
            $stream = new class (self::BODY) implements \Stringable {
                public int $reads = 0;

                public function __construct(private readonly string $body) {}

                public function __toString(): string
                {
                    return $this->reads++ === 0 ? $this->body : '';
                }
            };
            $result = $call($stream);
            self::assertSame(1, $stream->reads, $name);
            if ($result instanceof Event) {
                self::assertSame('webhook.test', $result->type, $name);
            } else {
                self::assertTrue($result, $name);
            }
        }
        self::assertSame([], $this->api->requests);
    }

    /** @return iterable<string, array{int|float, string}> */
    public static function tolerancesInTheMessage(): iterable
    {
        yield '300' => [300, '300'];
        yield '300.0' => [300.0, '300']; // como el {:g} de Python: sin «.0»
        yield '0.5' => [0.5, '0.5'];
        yield '1.25' => [1.25, '1.25'];
        yield '3600' => [3600, '3600'];
        // Sin el redondeo a 6 cifras del {:g} de Python (1.23457e+06 y 0.123457).
        yield '1234567' => [1234567, '1234567'];
        yield '0.1234567' => [0.1234567, '0.1234567'];
    }

    /**
     * De PHP: la tolerancia del mensaje, entera si es entera; si no, con todos sus decimales.
     * Con las de siempre (300, 3600) es lo mismo que en Python.
     */
    #[DataProvider('tolerancesInTheMessage')]
    public function testTheToleranceInTheClockMessage(int|float $tolerance, string $shown): void
    {
        $this->now = self::T + $tolerance + 3600.0;
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify(self::BODY, self::HEADER, self::SECRET, $tolerance),
        );
        self::assertSame(sprintf(self::CLOCK, self::T, $shown), $error->getMessage());
    }

    /**
     * El orden de los chequeos es el de Python: payload, secreto, tolerancia, tipo del header,
     * header que falta, t, v1, firma y reloj. Con dos problemas, gana el primero.
     */
    public function testChecksRunInPythonsOrder(): void
    {
        $this->now = self::T + 3600.0; // fuera de la tolerancia
        $unsigned = 't=' . self::T . ',v1=' . str_repeat('0', 64);
        $notRaw = sprintf(self::NOT_THE_RAW_BODY, 'array');
        $failure = SignatureVerificationException::class;
        $cases = [
            [static fn() => self::verify([], ['x'], '', -1), \TypeError::class, $notRaw],
            [
                static fn() => self::verify(self::BODY, ['x'], '', -1),
                \ValueError::class,
                self::NO_SECRET,
            ],
            [
                static fn() => self::verify(self::BODY, ['x'], self::SECRET, -1),
                \ValueError::class,
                self::BAD_TOLERANCE,
            ],
            // Una tolerancia que no es un número tampoco se adelanta al payload ni al secreto.
            [static fn() => self::verify([], ['x'], '', '300'), \TypeError::class, $notRaw],
            [
                static fn() => self::verify(self::BODY, ['x'], '', '300'),
                \ValueError::class,
                self::NO_SECRET,
            ],
            [
                static fn() => self::verify(self::BODY, ['x'], self::SECRET, '300'),
                \TypeError::class,
                self::NOT_SECONDS,
            ],
            [
                static fn() => self::verify(self::BODY, ['x'], self::SECRET),
                \TypeError::class,
                sprintf(self::NOT_A_HEADER, 'array'),
            ],
            [
                static fn() => self::verify(self::BODY, 'v1=no-es-hex', self::SECRET),
                $failure,
                self::NO_TIMESTAMP,
            ],
            // La firma antes que el reloj.
            [
                static fn() => self::verify(self::BODY, $unsigned, self::SECRET),
                $failure,
                self::MISMATCH,
            ],
            // computeSignature: payload, secreto y t.
            [
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                static fn() => WebhookSignature::computeSignature([], '', -1),
                \TypeError::class,
                $notRaw,
            ],
            [
                static fn() => WebhookSignature::computeSignature(self::BODY, '', -1),
                \ValueError::class,
                self::NO_SECRET,
            ],
            // generateHeader, como en Python: el timestamp antes que el payload y el secreto.
            [
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                static fn() => WebhookSignature::generateHeader([], '', -1),
                \ValueError::class,
                self::BAD_TIMESTAMP,
            ],
            [
                // @phpstan-ignore argument.type (lo que pasaría alguien sin PHPStan)
                static fn() => WebhookSignature::generateHeader([], ''),
                \TypeError::class,
                $notRaw,
            ],
            // constructEvent: la firma antes que el JSON.
            [
                static fn() => Webhook::constructEvent('no es json', $unsigned, self::SECRET),
                $failure,
                self::MISMATCH,
            ],
        ];
        foreach ($cases as $i => [$call, $class, $message]) {
            $error = self::thrown($call);
            self::assertSame(
                [$class, $message],
                [$error::class, $error->getMessage()],
                "caso {$i}",
            );
        }
    }

    /** Como errors="replace" en Python: lo que no es UTF-8 llega al error como U+FFFD. */
    public function testTheErrorCarriesTheBodyAsText(): void
    {
        $error = self::assertThrows(
            SignatureVerificationException::class,
            static fn() => self::verify("{\"nombre\":\"Jos\xe9\"}", self::HEADER, self::SECRET),
            self::MISMATCH,
        );
        self::assertSame("{\"nombre\":\"Jos\xef\xbf\xbd\"}", $error->getHttpBody());
    }

    /**
     * De PHP: cada $secret lleva #[\SensitiveParameter], así que desde PHP 8.2 las trazas de los
     * errores muestran un SensitiveParameterValue en su lugar. PHP 8.1 ignora el atributo (por
     * eso el README recomienda zend.exception_ignore_args=On, el valor de producción). Las
     * trazas se miran con sus argumentos, como en desarrollo, aunque el php.ini de quien corre
     * los tests sea el de producción: si no, no habría nada que mirar.
     */
    public function testTheSecretStaysOutOfTraces(): void
    {
        $methods = [
            [WebhookSignature::class, 'computeSignature'],
            [WebhookSignature::class, 'verifyHeader'],
            [WebhookSignature::class, 'generateHeader'],
            [Webhook::class, 'constructEvent'],
            [RayaClient::class, 'constructEvent'],
        ];
        foreach ($methods as [$class, $method]) {
            $parameter = new \ReflectionParameter([$class, $method], 'secret');
            $attributes = $parameter->getAttributes('SensitiveParameter');
            self::assertCount(1, $attributes, "{$class}::{$method}");
        }
        if (PHP_VERSION_ID < 80200) {
            return; // sin el atributo
        }

        $client = $this->api->client();
        $this->now = self::T + 3600.0; // el reloj, fuera de la tolerancia del vector
        $development = [
            'zend.exception_ignore_args' => '0',
            'zend.exception_string_param_max_len' => '1000000',
        ];
        $before = [];
        foreach ($development as $name => $value) {
            $previous = ini_set($name, $value);
            self::assertIsString($previous, "No pude cambiar {$name}.");
            $before[$name] = $previous;
        }
        try {
            $errors = [
                'verifyHeader' => self::thrown(
                    static fn() => WebhookSignature::verifyHeader(
                        self::BODY,
                        self::HEADER,
                        self::SECRET,
                    ),
                ),
                'computeSignature' => self::thrown(
                    static fn() => WebhookSignature::computeSignature(
                        self::BODY,
                        self::SECRET,
                        'x',
                    ),
                ),
                'generateHeader' => self::thrown(
                    static fn() => WebhookSignature::generateHeader(self::BODY, self::SECRET, -1),
                ),
                'Webhook::constructEvent' => self::thrown(
                    static fn() => Webhook::constructEvent(self::BODY, self::HEADER, self::SECRET),
                ),
                '$client->constructEvent' => self::thrown(
                    static fn() => $client->constructEvent(
                        '[1]',
                        WebhookSignature::generateHeader('[1]', self::SECRET),
                        self::SECRET,
                    ),
                ),
            ];
            $shown = array_map(static fn(\Throwable $error): string => (string) $error, $errors);
        } finally {
            foreach ($before as $name => $value) {
                ini_set($name, $value);
            }
        }
        foreach ($errors as $name => $error) {
            $hidden = 0;
            foreach ($error->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    self::assertNotSame(self::SECRET, $argument, $name);
                    // Por nombre: la clase no existe en PHP 8.1, que PHPStan también analiza.
                    if (get_debug_type($argument) === 'SensitiveParameterValue') {
                        $hidden++;
                    }
                }
            }
            self::assertGreaterThan(0, $hidden, $name);
            self::assertStringNotContainsString(self::SECRET, $shown[$name], $name);
        }
    }

    /**
     * WebhookSignature::verifyHeader con los argumentos tal cual llegan, como `verify =
     * rayacash.WebhookSignature.verify_header` en Python: los defaults son los del SDK, y los
     * tipos equivocados de los tests llegan sin cambios.
     */
    private static function verify(mixed ...$arguments): bool
    {
        // @phpstan-ignore argument.type (los tests pasan también tipos equivocados a propósito)
        return WebhookSignature::verifyHeader(...$arguments);
    }

    /** Un body de PSR-7 (un stream que se puede leer de nuevo): su texto es el body. */
    private static function stream(string $body): \Stringable
    {
        return new class ($body) implements \Stringable {
            public function __construct(private readonly string $body) {}

            public function __toString(): string
            {
                return $this->body;
            }
        };
    }
}
