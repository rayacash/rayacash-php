<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Json;
use RayaCash\Internal\Params;
use RayaCash\RayaClient;
use RayaCash\Tests\Support\FakeDecimal;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\TestCase;

/**
 * Cómo viajan los parámetros: montos exactos, el JSON del body, la query, los ids en la ruta y
 * la Idempotency-Key (test_encoding.py y encoding.test.ts). Los bytes son los de json.dumps de
 * Python: compacto, en ASCII, en el orden dado y sin escapar "/".
 *
 * El Decimal de Python es acá FakeDecimal: un \Stringable, como BcMath\Number o BigDecimal (lo
 * único que el SDK usa de ellos es su texto).
 */
final class EncodingTest extends TestCase
{
    private const NOT_A_NUMBER = ' no es un número: usá punto decimal y nada más, como "1500.50".';
    private const NOT_UTF8 = 'No se puede mandar texto que no es UTF-8 válido: convertilo con '
        . 'mb_convert_encoding() antes de llamar.';
    private const INVALID_KEY = 'idempotency_key tiene que tener de 1 a 255 caracteres ASCII '
        . 'visibles, sin espacios (por ejemplo, un UUID v4).';
    private const OPTIONS = 'las opciones son api_key, timeout, max_network_retries e '
        . 'idempotency_key (solo en los POST).';

    /** @return iterable<string, array{mixed, string}> */
    public static function amounts(): iterable
    {
        yield 'int 1500' => [1500, '1500'];
        yield 'float 1500.5' => [1500.5, '1500.5'];
        yield 'float 1500.1' => [1500.1, '1500.1'];
        yield 'float 25000.50' => [25000.50, '25000.5'];
        yield 'decimal 1500.50' => [new FakeDecimal('1500.50'), '1500.5'];
        yield 'decimal 1500.00' => [new FakeDecimal('1500.00'), '1500'];
        yield 'decimal 1E+3' => [new FakeDecimal('1E+3'), '1000'];
        yield 'decimal 0.10' => [new FakeDecimal('0.10'), '0.1'];
        yield 'string 1500.50' => ['1500.50', '1500.5'];
        yield 'string con espacios' => [' 25 ', '25'];
        yield 'string 1.5e3' => ['1.5e3', '1500'];
        // Sin redondear: la API lo rechaza.
        yield 'float 0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'decimal 1500.505' => [new FakeDecimal('1500.505'), '1500.505'];

        // Las de Node. Su bigint (1500n) es acá un int de 64 bits: nunca pasa por float. En PHP
        // de 32 bits no hay (ahí PHP_INT_MAX es 2^31 − 1).
        if (PHP_INT_SIZE === 8) {
            yield 'int de 64 bits' => [PHP_INT_MAX, '9223372036854775807'];
        }
        yield 'string +5' => ['+5', '5'];
        yield 'string .5' => ['.5', '0.5'];
        yield 'string -0.00' => ['-0.00', '-0'];
        yield 'float 1e21' => [1e21, '1000000000000000000000'];
        yield 'float 1e-7' => [1e-7, '0.0000001'];

        // Las de PHP.
        yield 'float -0.0, como Python' => [-0.0, '-0'];
        // Los espacios Unicode y el \n final salen, como con strip() en Python.
        yield 'espacios Unicode' => ["\u{a0}25\u{2003}", '25'];
        yield 'separadores U+001C a U+001F' => ["\x1c\x1d25\x1e\x1f", '25'];
        yield 'string con \n al final' => ["1500.50\n", '1500.5'];
        yield 'string 5.' => ['5.', '5'];
        yield 'ceros adelante' => ['007', '7'];
        yield 'decimal de 30 dígitos' => [
            new FakeDecimal('123456789012345678901234567890.10'),
            '123456789012345678901234567890.1',
        ];
        yield 'el float más chico' => [5e-324, '0.' . str_repeat('0', 323) . '5'];
        yield 'el float más grande' => [PHP_FLOAT_MAX, '17976931348623157' . str_repeat('0', 292)];
        // Sin tope para el exponente (como Number() en Node): solo cuentan los dígitos que quedan.
        yield 'el exponente compensa los decimales' => [
            '0.' . str_repeat('0', 9999) . '1e10000',
            '1',
        ];
        yield '1000 dígitos, el máximo' => ['1e999', '1' . str_repeat('0', 999)];
        // El tope cuenta también los decimales: 1000 después del punto.
        yield '1000 decimales, el máximo' => ['1e-1000', '0.' . str_repeat('0', 999) . '1'];
        $number = self::bcmath('1500.00');
        if ($number !== null) {
            yield 'BcMath\Number' => [$number, '1500'];
        }
    }

    #[DataProvider('amounts')]
    public function testAmountsTravelAsCanonicalJsonNumbers(mixed $value, string $expected): void
    {
        self::assertSame(
            '{"amount":' . $expected . '}',
            Json::encode(['amount' => Encoding::canonicalAmount($value)]),
        );
    }

    /** @return iterable<string, array{mixed, class-string<\Throwable>, string}> */
    public static function invalidAmounts(): iterable
    {
        $bool = 'amount no puede ser un bool: mandá un número en pesos, como 1500 o 1500.5.';
        $type = 'amount tiene que ser un número en pesos (int, float, string decimal o un decimal '
            . 'como BcMath\Number o BigDecimal), no ';
        $finite = 'amount tiene que ser un número finito, no ';
        $digits = 'amount tiene demasiados dígitos.';
        yield 'true' => [true, \TypeError::class, $bool];
        yield 'false' => [false, \TypeError::class, $bool];
        yield 'null' => [null, \TypeError::class, $type . 'null.'];
        yield 'lista' => [[1500], \TypeError::class, $type . 'array.'];
        yield 'NAN' => [NAN, \ValueError::class, $finite . 'NAN.'];
        yield 'INF' => [INF, \ValueError::class, $finite . 'INF.'];
        yield 'decimal NaN' => [new FakeDecimal('NaN'), \ValueError::class, $finite . 'NaN.'];
        yield 'decimal -Infinity' => [
            new FakeDecimal('-Infinity'),
            \ValueError::class,
            $finite . '-Infinity.',
        ];
        yield 'string abc' => ['abc', \ValueError::class, 'amount "abc"' . self::NOT_A_NUMBER];
        yield 'string 1.500,50' => [
            '1.500,50',
            \ValueError::class,
            'amount "1.500,50"' . self::NOT_A_NUMBER,
        ];
        yield 'string Infinity' => ['Infinity', \ValueError::class, $finite . 'Infinity.'];
        yield 'string vacío' => ['', \ValueError::class, 'amount ""' . self::NOT_A_NUMBER];

        // Las de Node (su {} es acá un stdClass; undefined y new Number(1500) no existen en PHP).
        yield 'objeto' => [new \stdClass(), \TypeError::class, $type . 'stdClass.'];
        yield 'string 1e999999' => ['1e999999', \ValueError::class, $digits];

        // Las de PHP.
        yield '-INF' => [-INF, \ValueError::class, $finite . '-INF.'];
        // El Decimal("sNaN") de la prueba de dumps en Python: acá llega como monto.
        yield 'decimal sNaN' => [new FakeDecimal('sNaN'), \ValueError::class, $finite . 'sNaN.'];
        yield 'solo espacios' => [' ', \ValueError::class, 'amount " "' . self::NOT_A_NUMBER];
        yield 'solo un punto' => ['.', \ValueError::class, 'amount "."' . self::NOT_A_NUMBER];
        yield 'exponente sin dígitos' => [
            '1e',
            \ValueError::class,
            'amount "1e"' . self::NOT_A_NUMBER,
        ];
        yield '1001 dígitos' => ['1e1000', \ValueError::class, $digits];
        // Del lado de los decimales también, antes de armar el texto: «1e-99999999999», de un
        // formulario, serían 100 GB.
        yield '1001 decimales' => ['1e-1001', \ValueError::class, $digits];
        yield 'string 1e-99999999999' => ['1e-99999999999', \ValueError::class, $digits];
        yield 'UTF-8 inválido' => [
            "\xff",
            \ValueError::class,
            "amount \"\u{fffd}\"" . self::NOT_A_NUMBER,
        ];
        // Divergencias declaradas: Decimal() de Python acepta dígitos Unicode y guiones bajos.
        yield 'dígitos que no son ASCII' => [
            '١٥٠٠',
            \ValueError::class,
            'amount "١٥٠٠"' . self::NOT_A_NUMBER,
        ];
        yield 'guion bajo' => ['1_500', \ValueError::class, 'amount "1_500"' . self::NOT_A_NUMBER];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsFailBeforeSending(
        mixed $value,
        string $class,
        string $message,
    ): void {
        // Con poca memoria de sobra: si el tope de dígitos dejara de andar, «1e-99999999999» sería
        // un error de PHP en el acto, y no 100 GB pedidos a la máquina (sin memory_limit).
        $limit = ini_set('memory_limit', (string) (memory_get_usage(true) + 256 * 1024 * 1024));
        self::assertIsString($limit);
        try {
            self::assertFails($class, $message, static fn() => Encoding::canonicalAmount($value));
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    public function testDumpsHandlesEveryJsonType(): void
    {
        $value = [
            's' => 'ñandú «hola»',
            'i' => 1,
            'f' => 1.25,
            // El Decimal de Python: BcMath\Number (PHP 8.4+) o, si no hay, el número exacto que
            // arma el SDK para un monto (Json no manda un \Stringable cualquiera como número).
            'd' => self::bcmath('2.50') ?? Encoding::canonicalAmount(new FakeDecimal('2.50')),
            'b' => true,
            'n' => null,
            'l' => [1, false, ['x']], // la tupla de Python es una lista
            'o' => ['k' => 'v'],
        ];
        self::assertSame(
            self::ascii(
                '{"s":"U+00F1andU+00FA U+00ABholaU+00BB","i":1,"f":1.25,"d":2.5,"b":true,"n":null,'
                . '"l":[1,false,["x"]],"o":{"k":"v"}}',
            ),
            Json::encode($value),
        );

        // Node: lo que está fuera del BMP va como par de surrogates, y DEL también se escapa (su
        // bigint no hace falta: un int de PHP ya es un entero exacto).
        self::assertSame(
            self::ascii('{"emoji":"U+D83DU+DE00","del":"U+007F"}'),
            Json::encode(['emoji' => "\u{1F600}", 'del' => "\x7f"]),
        );

        // PHP: "/" sin escapar (json_encode lo escaparía; json.dumps, no).
        self::assertSame(
            '{"url":"https://raya.cash/payments/x"}',
            Json::encode(['url' => 'https://raya.cash/payments/x']),
        );
        // [] es una lista vacía y un stdClass, un objeto (con sus claves aunque sean números).
        self::assertSame(
            '{"lista":[],"objeto":{},"claves":{"0":"a"}}',
            Json::encode(['lista' => [], 'objeto' => new \stdClass(), 'claves' => (object) ['a']]),
        );
        // La fila {1: "x"} de Python: en PHP una clave int es una clave JSON ('1' se guarda como
        // 1, así que no puede fallar).
        self::assertSame('{"1":"x"}', Json::encode([1 => 'x']));
        self::assertSame('{"a":[1]}', Json::encode(new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['a' => [1]];
            }
        }));
        // Divergencia declarada: un float va en notación normal (Python manda 1e+25 y -0.0).
        self::assertSame(
            '{"f":10000000000000000000000000,"z":-0}',
            Json::encode(['f' => 1e25, 'z' => -0.0]),
        );
        // PHP: un BigDecimal de brick/math también va como número, en cualquier versión de PHP,
        // aunque su jsonSerialize() dé un string ("2.50").
        require_once __DIR__ . '/Support/brick-math.php';
        self::assertSame(
            '{"d":2.5,"l":[-1]}',
            Json::encode(['d' => BigDecimal::of('2.50'), 'l' => [BigDecimal::of('-1.0')]]),
        );
    }

    /** @return iterable<string, array{mixed, class-string<\Throwable>, string}> */
    public static function unsendable(): iterable
    {
        // Python: {1: "x"}, una clave que no es str. En PHP una clave int es una clave JSON (ver
        // testDumpsHandlesEveryJsonType): la que no se puede mandar es la que no es UTF-8.
        yield 'clave que no es UTF-8' => [["\xff" => 'x'], \ValueError::class, self::NOT_UTF8];
        // Python: object(), un objeto cualquiera.
        yield 'objeto' => [
            ['x' => new class {}],
            \TypeError::class,
            'No se puede mandar un class@anonymous en JSON.',
        ];
        yield 'NAN' => [['x' => NAN], \ValueError::class, 'No se puede mandar NAN en JSON.'];
        // Python: Decimal("sNaN"). BcMath\Number y BigDecimal siempre son finitos: acá, INF (el
        // sNaN de un monto está en invalidAmounts).
        yield 'INF' => [['x' => INF], \ValueError::class, 'No se puede mandar INF en JSON.'];

        // Las de Node: una función, un Symbol (acá, un resource), un Date y un Map.
        yield 'función' => [
            ['x' => static fn(): int => 1],
            \TypeError::class,
            'No se puede mandar un Closure en JSON.',
        ];
        yield 'resource' => [
            ['x' => STDERR],
            \TypeError::class,
            'No se puede mandar un resource (stream) en JSON.',
        ];
        yield 'fecha' => [
            ['x' => new \DateTimeImmutable('2026-10-24T00:00:00Z')],
            \TypeError::class,
            'No se puede mandar un DateTimeImmutable en JSON.',
        ];
        yield 'Map' => [
            ['x' => new \ArrayObject(['k' => 'v'])],
            \TypeError::class,
            'No se puede mandar un ArrayObject en JSON.',
        ];
        // Node: FakeDecimal("sNaN"). Json solo manda como número BcMath\Number y BigDecimal: otro
        // \Stringable (un stream PSR-7, por ejemplo) no viaja. Un monto, sí (canonicalAmount).
        yield 'Stringable' => [
            ['x' => new FakeDecimal('1')],
            \TypeError::class,
            'No se puede mandar un RayaCash\Tests\Support\FakeDecimal en JSON.',
        ];

        // Las de PHP.
        yield 'texto que no es UTF-8' => [['x' => "\xff"], \ValueError::class, self::NOT_UTF8];
        yield '-INF en una lista' => [
            ['x' => [1, -INF]],
            \ValueError::class,
            'No se puede mandar -INF en JSON.',
        ];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('unsendable')]
    public function testDumpsRejectsWhatJsonCannotCarry(
        mixed $value,
        string $class,
        string $message,
    ): void {
        self::assertFails($class, $message, static fn() => Json::encode($value));
    }

    public function testEncodeBodyDropsOnlyTopLevelNone(): void
    {
        $body = Encoding::body([
            'amount' => Encoding::canonicalAmount(new FakeDecimal('15000')),
            'description' => null,
            'metadata' => ['a' => null],
        ]);
        self::assertSame('{"amount":15000,"metadata":{"a":null}}', $body);

        // PHP: siempre un objeto JSON en ASCII ({} aunque no venga nada), también desde un
        // stdClass.
        self::assertSame('{}', Encoding::body([]));
        self::assertSame('{}', Encoding::body(['description' => null]));
        self::assertSame('{"a":1}', Encoding::body((object) ['a' => 1, 'b' => null]));
        self::assertSame(
            self::ascii('{"description":"U+00F1andU+00FA"}'),
            Encoding::body(['description' => 'ñandú']),
        );
    }

    public function testEncodeQuery(): void
    {
        $query = Encoding::query([
            'direction' => 'inbound',
            'status' => null,
            'created_gte' => new \DateTimeImmutable('2026-10-23', new \DateTimeZone('-03:00')),
            'created_lt' => '2026-10-24T00:00:00-03:00', // Node: un string pasa tal cual
            'limit' => 100,
            'cursor' => null,
        ]);
        self::assertSame(
            [
                'direction' => 'inbound',
                'created_gte' => '2026-10-23T00:00:00-03:00',
                'created_lt' => '2026-10-24T00:00:00-03:00',
                'limit' => '100',
            ],
            $query,
        );

        // PHP: como isoformat(), con los microsegundos solo si hay. Un DateTime también sirve, y
        // una zona con nombre va como su offset.
        self::assertSame(
            ['a' => '2026-10-23T03:00:00.500000+00:00', 'b' => '2026-10-23T00:00:00-03:00'],
            Encoding::query([
                'a' => new \DateTime('2026-10-23 03:00:00.5', new \DateTimeZone('UTC')),
                'b' => new \DateTimeImmutable(
                    '2026-10-23',
                    new \DateTimeZone('America/Argentina/Buenos_Aires'),
                ),
            ]),
        );
        // La query viaja con rawurlencode (RFC 3986): el "+" de un offset no llega como espacio y
        // el espacio va como %20 (divergencia declarada: Python lo manda como +). Sin filtros, sin
        // "?".
        self::assertSame(
            '?created_gte=2026-10-23T00%3A00%3A00%2B00%3A00&cursor=a%20b%2Fc',
            Encoding::queryString(
                ['created_gte' => '2026-10-23T00:00:00+00:00', 'cursor' => 'a b/c'],
            ),
        );
        self::assertSame('', Encoding::queryString([]));
    }

    public function testEncodeQueryRequiresTimezone(): void
    {
        // Python rechaza un datetime sin zona. En PHP no existe: un DateTimeInterface siempre
        // tiene zona (la de date.timezone si no le pasás una), así que viaja con su offset y la
        // API nunca recibe una fecha sin zona. Tampoco hay fechas inválidas, que Node rechaza:
        // new DateTimeImmutable('no es una fecha') ya falla.
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/Argentina/Buenos_Aires');
        try {
            $query = Encoding::query(['created_lt' => new \DateTimeImmutable('2026-10-24')]);
        } finally {
            date_default_timezone_set($timezone);
        }
        self::assertSame(['created_lt' => '2026-10-24T00:00:00-03:00'], $query);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function otherQueryTypes(): iterable
    {
        yield 'bool' => [true, 'limit no puede ser un bool.'];
        yield 'float' => [1.5, 'limit no acepta un float.'];
        // El object() de Python y el {} de Node.
        yield 'objeto' => [new \stdClass(), 'limit no acepta un stdClass.'];
        // Node.
        yield 'array' => [[1], 'limit no acepta un array.'];
    }

    #[DataProvider('otherQueryTypes')]
    public function testEncodeQueryRejectsOtherTypes(mixed $value, string $message): void
    {
        self::assertFails(
            \TypeError::class,
            $message,
            static fn() => Encoding::query(['limit' => $value]),
        );
    }

    public function testPathIdEncodesEverything(): void
    {
        self::assertSame('a%2Fb%20c%3F', Encoding::pathId('paymentId', 'a/b c?'));
        // Node: solo quedan sin codificar las letras, los dígitos y -_.~ (quote(safe="")).
        self::assertSame('%21%27%28%29%2A~-_.', Encoding::pathId('paymentId', "!'()*~-_."));
        self::assertSame(Fixtures::PAYMENT_ID, Encoding::pathId('paymentId', Fixtures::PAYMENT_ID));
        // PHP: UTF-8 byte por byte, como quote().
        self::assertSame('%C3%B1%25%2B', Encoding::pathId('paymentId', 'ñ%+'));
    }

    /**
     * rawurlencode() deja los puntos (como quote() en Python) y curl resuelve los segmentos «.» y
     * «..»: retrieve('.') pediría /v1/transfers/ y refund('..'), POST /v1/refund. Fallan antes de
     * mandar nada, como en Python y Node. Con más puntos, o con algo más, no es un segmento de
     * puntos y viaja tal cual.
     */
    public function testPathIdRejectsDotSegments(): void
    {
        foreach (['.', '..'] as $dots) {
            self::assertFails(
                \ValueError::class,
                'paymentId no puede ser "." ni "..".',
                static fn() => Encoding::pathId('paymentId', $dots),
            );
        }
        self::assertSame('...', Encoding::pathId('paymentId', '...'));
        self::assertSame('.a', Encoding::pathId('paymentId', '.a'));
        self::assertSame('%20.%20', Encoding::pathId('paymentId', ' . '));
    }

    /** @return iterable<string, array{mixed, class-string<\Throwable>, string}> */
    public static function emptyIds(): iterable
    {
        yield 'vacío' => ['', \ValueError::class, 'paymentId no puede estar vacío.'];
        yield 'espacios' => ['  ', \ValueError::class, 'paymentId no puede estar vacío.'];
        // Para el strip() de Python, U+001C a U+001F también son espacios.
        yield 'separadores' => ["\x1c\x1f", \ValueError::class, 'paymentId no puede estar vacío.'];
        // En PHP el tipo lo controla el motor, en la firma pública: el error nombra $paymentId.
        $type = 'RayaCash\Service\PaymentService::retrieve(): Argument #1 ($paymentId) must be of '
            . 'type string, ';
        yield 'int' => [123, \TypeError::class, $type . 'int given'];
        yield 'null' => [null, \TypeError::class, $type . 'null given'];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('emptyIds')]
    public function testPathIdRejectsEmptyIds(mixed $value, string $class, string $message): void
    {
        $payments = $this->api->client()->payments;
        // @phpstan-ignore argument.type (ids que no son string, a propósito)
        $error = self::thrown(static fn() => $payments->retrieve($value));
        // Sin el «, called in …» que PHP les agrega a sus TypeError.
        self::assertSame(
            [$class, $message],
            [$error::class, preg_replace('/, called in .*\z/s', '', $error->getMessage())],
        );
        self::assertSame([], $this->api->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function validIdempotencyKeys(): iterable
    {
        yield 'UUID v4' => ['9b2f6c4e-1d3a-4e8b-a5c7-0f9e8d7c6b5a'];
        yield 'un carácter' => ['x'];
        yield '255 caracteres' => [str_repeat('~', 255)];
        yield 'símbolos' => ['!#$%'];
    }

    #[DataProvider('validIdempotencyKeys')]
    public function testValidIdempotencyKeys(string $key): void
    {
        self::assertSame($key, Encoding::idempotencyKey($key));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdempotencyKeys(): iterable
    {
        yield 'vacía' => [''];
        yield 'con espacio' => ['a b'];
        yield 'no ASCII' => ['ñ'];
        yield '256 caracteres' => [str_repeat('x', 256)];
        yield 'con tab' => ["tab\there"];
        // Con \z: en PHP, «$» también aceptaría un \n al final.
        yield 'con \n al final' => ["line\n"];
        // PHP: DEL, justo después de «~».
        yield 'DEL' => ["\x7f"];
    }

    #[DataProvider('invalidIdempotencyKeys')]
    public function testInvalidIdempotencyKeys(string $key): void
    {
        self::assertFails(
            \ValueError::class,
            self::INVALID_KEY,
            static fn() => Encoding::idempotencyKey($key),
        );
    }

    public function testIdempotencyKeyMustBeAString(): void
    {
        foreach ([123, null] as $key) {
            self::assertFails(
                \TypeError::class,
                'idempotency_key tiene que ser un string: generá un UUID v4 con '
                . 'RayaCash\Util\Uuid::v4() y guardalo junto a la operación antes de llamar.',
                static fn() => Encoding::idempotencyKey($key),
            );
        }
    }

    /** Node (checkParams): un parámetro desconocido falla antes de mandar nada. */
    public function testCheckParamsRejectsUnknownParameters(): void
    {
        $create = ['amount', 'destination'];
        $example = "['amount' => 25000]";
        self::assertSame([], Params::check('transfers->create', [], $create, $example));
        self::assertSame(
            ['amount' => 1, 'destination' => 'x'],
            Params::check(
                'transfers->create',
                ['amount' => 1, 'destination' => 'x'],
                $create,
                $example,
            ),
        );
        self::assertFails(
            \TypeError::class,
            'transfers->create no acepta "destinaton": los parámetros son amount y destination.',
            static fn() => Params::check(
                'transfers->create',
                ['amount' => 1, 'destinaton' => 'x'],
                $create,
                $example,
            ),
        );
        self::assertFails(
            \TypeError::class,
            'payments->create no acepta idempotencyKey entre los parámetros: va en las opciones, '
            . "el último argumento (['idempotency_key' => …]).",
            static fn() => Params::check(
                'payments->create',
                ['amount' => 1, 'idempotencyKey' => 'k'],
                ['amount'],
                "['amount' => 15000]",
            ),
        );
        // Node: un string en lugar del objeto. En PHP la firma pide un array: falla una lista.
        self::assertFails(
            \TypeError::class,
            "transfers->list recibe un array con los nombres de los parámetros, como ['direction' "
            . "=> 'inbound'], no una lista.",
            static fn() => Params::check(
                'transfers->list',
                ['inbound'],
                ['direction', 'status', 'limit'],
                "['direction' => 'inbound']",
            ),
        );
    }

    /** @return iterable<string, array{\Closure(RayaClient): mixed, string}> */
    public static function unknownKeys(): iterable
    {
        yield 'parámetro en camelCase' => [
            static fn(RayaClient $client): mixed => $client->payments->create(
                ['amount' => 1, 'externalReference' => 'pedido-1042'],
            ),
            'payments->create no acepta "externalReference" (¿quisiste decir external_reference?): '
            . 'los parámetros son amount, description, external_reference, return_url y metadata.',
        ];
        yield 'filtro en camelCase' => [
            static fn(RayaClient $client): mixed => $client->transfers->list(
                ['createdGte' => '2026-10-23T00:00:00-03:00'],
            ),
            'transfers->list no acepta "createdGte" (¿quisiste decir created_gte?): los parámetros '
            . 'son direction, status, created_gte, created_lt, limit y cursor.',
        ];
        yield 'parámetro sin pista' => [
            static fn(RayaClient $client): mixed => $client->transfers->create(
                // @phpstan-ignore argument.type (falta destination, a propósito)
                ['amount' => 1, 'destinaton' => 'x'],
                ['idempotency_key' => 'k'],
            ),
            'transfers->create no acepta "destinaton": los parámetros son amount y destination.',
        ];
        yield 'opción apiKey' => [
            static fn(RayaClient $client): mixed => $client->balance->retrieve(
                ['apiKey' => Fixtures::OTHER_KEY],
            ),
            'options no acepta "apiKey" (¿quisiste decir api_key?): ' . self::OPTIONS,
        ];
        yield 'opción idempotencyKey' => [
            static fn(RayaClient $client): mixed => $client->payments->refund(
                Fixtures::PAYMENT_ID,
                ['idempotencyKey' => 'k'],
            ),
            'options no acepta "idempotencyKey" (¿quisiste decir idempotency_key?): '
            . self::OPTIONS,
        ];
        yield 'opción maxNetworkRetries' => [
            static fn(RayaClient $client): mixed => $client->transfers->retrieve(
                't',
                ['maxNetworkRetries' => 0],
            ),
            'options no acepta "maxNetworkRetries" (¿quisiste decir max_network_retries?): '
            . self::OPTIONS,
        ];
        yield 'opción sin pista' => [
            static fn(RayaClient $client): mixed => $client->payments->retrieve(
                'p',
                ['retries' => 0],
            ),
            'options no acepta "retries": ' . self::OPTIONS,
        ];
    }

    /**
     * PHP: una clave desconocida, entre los parámetros o las opciones, falla antes de mandar; si
     * es el camelCase de una válida, el error dice cuál («¿quisiste decir…?»).
     *
     * @param \Closure(RayaClient): mixed $call
     */
    #[DataProvider('unknownKeys')]
    public function testUnknownKeysSuggestTheSnakeCaseName(\Closure $call, string $message): void
    {
        $client = $this->api->client();
        self::assertFails(\TypeError::class, $message, static fn() => $call($client));
        self::assertSame([], $this->api->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function optionsAmongTheParams(): iterable
    {
        yield 'api_key' => ['api_key', 'api_key'];
        yield 'apiKey' => ['apiKey', 'api_key'];
        yield 'timeout' => ['timeout', 'timeout'];
        yield 'max_network_retries' => ['max_network_retries', 'max_network_retries'];
        yield 'maxNetworkRetries' => ['maxNetworkRetries', 'max_network_retries'];
        yield 'idempotency_key' => ['idempotency_key', 'idempotency_key'];
        yield 'idempotencyKey' => ['idempotencyKey', 'idempotency_key'];
    }

    /** PHP: una opción entre los parámetros se perdería; el error dice dónde va. */
    #[DataProvider('optionsAmongTheParams')]
    public function testOptionsAmongTheParamsGoInTheLastArgument(string $name, string $option): void
    {
        $payments = $this->api->client()->payments;
        self::assertFails(
            \TypeError::class,
            "payments->create no acepta {$name} entre los parámetros: va en las opciones, el "
            . "último argumento (['{$option}' => …]).",
            // @phpstan-ignore argument.type (una opción entre los parámetros, a propósito)
            static fn() => $payments->create(['amount' => 1, $name => 'x']),
        );
        self::assertSame([], $this->api->requests);
    }

    /**
     * PHP: los bytes del body no dependen de serialize_precision ni de precision (los cambia un
     * php.ini o un framework). Las potencias de 2 son las difíciles: el hueco de arriba es el
     * doble que el de abajo, y el %e más cercano no siempre es el decimal más corto que vuelve
     * al mismo float (el de repr() en Python).
     */
    public function testBodyBytesDoNotDependOnPhpIni(): void
    {
        $floats = [
            '0.1 + 0.2' => 0.1 + 0.2,
            '1500.1' => 1500.1,
            '48169.59' => 48169.59,
            '1/3' => 1 / 3,
            '1e21' => 1e21,
            '1e-7' => 1e-7,
            '0.0' => 0.0,
            '-0.0' => -0.0,
            '5e-324' => 5e-324,
            'PHP_FLOAT_MAX' => PHP_FLOAT_MAX,
        ];
        foreach (range(-1074, 1023) as $exponent) {
            $floats["2^{$exponent}"] = 2.0 ** $exponent;
            $floats["-2^{$exponent}"] = -(2.0 ** $exponent);
        }
        $ini = [ini_get('serialize_precision'), ini_get('precision')];
        try {
            // Los de php.ini-development y php.ini-production.
            $default = $this->bodies($floats, '-1', '14');
            $changed = $this->bodies($floats, '17', '10');
        } finally {
            ini_set('serialize_precision', (string) $ini[0]);
            ini_set('precision', (string) $ini[1]);
        }

        $different = [];
        foreach ($default as $name => $body) {
            if ($body !== $changed[$name]) {
                $different[] = sprintf(
                    '%s: %s → %s',
                    $name,
                    self::abbreviate($body),
                    self::abbreviate($changed[$name]),
                );
            }
        }
        self::assertSame([], $different, 'Con serialize_precision=17 cambian los bytes.');
        // Los dígitos de repr() en Python, con cualquier php.ini.
        foreach ([$default, $changed] as $bodies) {
            self::assertSame(
                '{"amount":0.30000000000000004,"f":[0.30000000000000004]}',
                $bodies['0.1 + 0.2'],
            );
            self::assertSame(
                '{"amount":0.00000005960464477539063,"f":[0.00000005960464477539063]}',
                $bodies['2^-24'],
            );
            self::assertSame(
                '{"amount":618970019642690200000000000,"f":[618970019642690200000000000]}',
                $bodies['2^89'],
            );
            self::assertSame(
                self::ascii(
                    '{"amount":0.00000005960464477539063,"description":"U+00F1andU+00FA",'
                    . '"metadata":{"k":"v"}}',
                ),
                $bodies['payments->create'],
            );
        }
    }

    /**
     * Los floats de (string) $obj: repr() en Python 3, que es lo que escribe json.dumps (con
     * Infinity y NaN). Exponente cuando el punto queda a más de 16 cifras o detrás de 4 ceros.
     *
     * @return iterable<string, array{float, string}>
     */
    public static function pythonFloats(): iterable
    {
        yield '1500.1' => [1500.1, '1500.1'];
        yield '0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'];
        yield '25000.0' => [25000.0, '25000.0'];
        yield '-2.5' => [-2.5, '-2.5'];
        yield '0.0' => [0.0, '0.0'];
        yield '-0.0' => [-0.0, '-0.0'];
        yield '1/3' => [1 / 3, '0.3333333333333333'];
        yield '0.0001' => [0.0001, '0.0001'];
        yield '0.00001' => [0.00001, '1e-05'];
        yield '1.5e-7' => [1.5e-7, '1.5e-07'];
        yield '2^-24' => [2.0 ** -24, '5.960464477539063e-08'];
        yield '5e-324' => [5e-324, '5e-324'];
        yield '1e15' => [1e15, '1000000000000000.0'];
        yield '9999999999999998.0' => [9999999999999998.0, '9999999999999998.0'];
        yield '1e16' => [1e16, '1e+16'];
        yield '-1e16' => [-1e16, '-1e+16'];
        yield '123456789012345678.0' => [123456789012345678.0, '1.2345678901234568e+17'];
        yield '2^89' => [2.0 ** 89, '6.189700196426902e+26'];
        yield 'PHP_FLOAT_MAX' => [PHP_FLOAT_MAX, '1.7976931348623157e+308'];
        yield 'INF' => [INF, 'Infinity'];
        yield '-INF' => [-INF, '-Infinity'];
        yield 'NAN' => [NAN, 'NaN'];
    }

    /** Con cualquier serialize_precision: los dígitos salen de floatText(). */
    #[DataProvider('pythonFloats')]
    public function testFloatReprIsWhatPythonWrites(float $value, string $expected): void
    {
        $ini = ini_get('serialize_precision');
        try {
            foreach (['-1', '17'] as $precision) {
                ini_set('serialize_precision', $precision);
                self::assertSame($expected, Encoding::floatRepr($value), $precision);
            }
        } finally {
            ini_set('serialize_precision', (string) $ini);
        }
    }

    /**
     * Cada float como monto y suelto, y el body entero de una request, con ese php.ini.
     *
     * @param array<string, float> $floats
     * @return array<string, string>
     */
    private function bodies(array $floats, string $serializePrecision, string $precision): array
    {
        ini_set('serialize_precision', $serializePrecision);
        ini_set('precision', $precision);
        $bodies = [];
        foreach ($floats as $name => $float) {
            $bodies[$name] = Json::encode(
                ['amount' => Encoding::canonicalAmount($float), 'f' => [$float]],
            );
        }
        $this->api->add(201, Fixtures::PAYMENT_PENDING);
        $this->api->client()->payments->create(
            ['amount' => 2.0 ** -24, 'description' => 'ñandú', 'metadata' => ['k' => 'v']],
            ['idempotency_key' => 'pedido-1042-pago'],
        );
        $bodies['payments->create'] = (string) $this->api->last()->body;
        return $bodies;
    }

    /** Un body largo, recortado para el mensaje de error. */
    private static function abbreviate(string $text): string
    {
        return strlen($text) > 120 ? substr($text, 0, 50) . '…' . substr($text, -50) : $text;
    }

    /** Que $fn lance exactamente $class (no una subclase) con exactamente $message. */
    private static function assertFails(string $class, string $message, callable $fn): void
    {
        $error = self::thrown($fn);
        self::assertSame([$class, $message], [$error::class, $error->getMessage()]);
    }

    /**
     * Los JSON esperados escriben cada escape \uXXXX como U+XXXX, sin la barra invertida, para que
     * no se confunda con un escape de PHP: ascii() pone la barra, la u y el código en minúscula,
     * como json.dumps (U+00F1, la «ñ», queda como la barra seguida de u00f1).
     */
    private static function ascii(string $json): string
    {
        return (string) preg_replace_callback(
            '/U\+([0-9A-F]{4})/',
            static fn(array $match): string => chr(92) . 'u' . strtolower($match[1]),
            $json,
        );
    }

    /** Un BcMath\Number (PHP 8.4+ con bcmath), o null. Por nombre: PHPStan analiza desde 8.1. */
    private static function bcmath(string $text): ?\Stringable
    {
        $class = 'BcMath\Number';
        if (!class_exists($class)) {
            return null;
        }
        $number = new $class($text);
        return $number instanceof \Stringable ? $number : null;
    }
}
