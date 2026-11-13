<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Checkout;
use RayaCash\ConnectOrder;
use RayaCash\Event;
use RayaCash\Internal\Deprecation;
use RayaCash\RayaCash;
use RayaCash\RayaClient;
use RayaCash\RayaObject;
use RayaCash\Service\CheckoutOrderService;
use RayaCash\Service\CheckoutService;
use RayaCash\Service\ConnectOrderService;
use RayaCash\Service\ConnectService;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\TestCase;
use RayaCash\WebhookSignature;

/**
 * Los nombres de la 1.1.0, deprecados hasta la 2.0.0 (deprecated.test.ts en Node): son alias de
 * las clases de Raya Connect, la misma clase, y llaman a las rutas nuevas. El alias existe apenas
 * carga la clase nueva (si opcache.preload la precargó, apenas carga el autoload de Composer:
 * src/aliases.php); el archivo del nombre viejo carga solo si tu código lo nombra primero, y
 * ahí avisa una vez con un E_USER_DEPRECATED silenciado con @ (src/Internal/Deprecation.php).
 *
 * Acá las clases nuevas se cargan en setUp(), así que los alias ya existen y usar un nombre viejo
 * no carga su archivo: el aviso se prueba con require y en otro proceso.
 */
final class DeprecatedTest extends TestCase
{
    /** Las clases y las interfaces públicas de la 1.1.0: todo lo de src/ salvo Internal\. */
    private const PUBLIC_1_1_0 = [
        'RayaCash\Balance',
        'RayaCash\Checkout',
        'RayaCash\Checkout\BackUrls',
        'RayaCash\Checkout\BankTransfer',
        'RayaCash\Checkout\Item',
        'RayaCash\Checkout\Payer',
        'RayaCash\Event',
        'RayaCash\Event\Data',
        'RayaCash\Exception\ApiConnectionException',
        'RayaCash\Exception\ApiException',
        'RayaCash\Exception\AuthenticationException',
        'RayaCash\Exception\ConflictException',
        'RayaCash\Exception\IdempotencyException',
        'RayaCash\Exception\InvalidRequestException',
        'RayaCash\Exception\NotFoundException',
        'RayaCash\Exception\PermissionDeniedException',
        'RayaCash\Exception\RateLimitException',
        'RayaCash\Exception\RayaException',
        'RayaCash\Exception\SignatureVerificationException',
        'RayaCash\Exception\UnprocessableEntityException',
        'RayaCash\HttpClient\CurlClient',
        'RayaCash\HttpClient\Headers',
        'RayaCash\HttpClient\HttpClientInterface',
        'RayaCash\HttpClient\Request',
        'RayaCash\HttpClient\Response',
        'RayaCash\HttpClient\TransportException',
        'RayaCash\ListObject',
        'RayaCash\OAuth',
        'RayaCash\OAuthToken',
        'RayaCash\Payment',
        'RayaCash\Payment\BankTransfer',
        'RayaCash\Payment\Payer',
        'RayaCash\RayaCash',
        'RayaCash\RayaClient',
        'RayaCash\RayaObject',
        'RayaCash\RayaResponse',
        'RayaCash\Service\BalanceService',
        'RayaCash\Service\CheckoutOrderService',
        'RayaCash\Service\CheckoutService',
        'RayaCash\Service\OAuthService',
        'RayaCash\Service\PaymentService',
        'RayaCash\Service\TransferService',
        'RayaCash\Service\UserService',
        'RayaCash\Transfer',
        'RayaCash\Transfer\Counterparty',
        'RayaCash\User',
        'RayaCash\User\Address',
        'RayaCash\User\Business',
        'RayaCash\User\Identification',
        'RayaCash\User\Merchant',
        'RayaCash\User\Representative',
        'RayaCash\Util\LoggerInterface',
        'RayaCash\Util\Uuid',
        'RayaCash\Webhook',
        'RayaCash\WebhookSignature',
    ];

    /** Cada nombre de la 1.1.0 que cambió, con el nuevo: lo único que suma la 1.2.0. */
    private const ALIASES = [
        Checkout::class => ConnectOrder::class,
        Checkout\BackUrls::class => ConnectOrder\BackUrls::class,
        Checkout\BankTransfer::class => ConnectOrder\BankTransfer::class,
        Checkout\Item::class => ConnectOrder\Item::class,
        Checkout\Payer::class => ConnectOrder\Payer::class,
        CheckoutOrderService::class => ConnectOrderService::class,
        CheckoutService::class => ConnectService::class,
    ];

    /** Las constantes de la 1.1.0 que cambiaron, con la nueva: valen lo mismo. */
    private const EVENT_TYPES = [
        'TYPE_CHECKOUT_CREATED' => 'TYPE_CONNECT_ORDER_CREATED',
        'TYPE_CHECKOUT_SUCCEEDED' => 'TYPE_CONNECT_ORDER_SUCCEEDED',
        'TYPE_CHECKOUT_FAILED' => 'TYPE_CONNECT_ORDER_FAILED',
        'TYPE_CHECKOUT_REFUNDED' => 'TYPE_CONNECT_ORDER_REFUNDED',
    ];

    private const ITEM = ['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ALIASES as $new) {
            self::assertTrue(class_exists($new));
        }
    }

    public function testEverythingPublicInTheOldVersionStillExists(): void
    {
        foreach (self::PUBLIC_1_1_0 as $name) {
            self::assertTrue(class_exists($name) || interface_exists($name), $name);
        }
        // Lo nuevo de src/ (sin Internal\) son las clases de Raya Connect (las que reemplazan a
        // los nombres viejos y las de los payouts, que son nuevas, sin alias), las de Identidad y
        // los dos servicios de los test helpers del sandbox, nada más.
        $new = array_values(array_diff(self::publicClassesInSrc(), self::PUBLIC_1_1_0));
        $expected = [
            ...array_values(self::ALIASES),
            'RayaCash\ConnectPayout',
            'RayaCash\ConnectPayout\Counterparty',
            'RayaCash\ConnectPayout\Origin',
            'RayaCash\Service\ConnectPayoutService',
            'RayaCash\IdentityVerification',
            'RayaCash\IdentityVerification\Address',
            'RayaCash\IdentityVerification\Checks',
            'RayaCash\IdentityVerification\DeclineReason',
            'RayaCash\IdentityVerification\DocumentCheck',
            'RayaCash\IdentityVerification\FaceMatchCheck',
            'RayaCash\IdentityVerification\IdentityDocument',
            'RayaCash\IdentityVerification\LivenessCheck',
            'RayaCash\IdentityVerification\Person',
            'RayaCash\IdentityVerification\RegistryCheck',
            'RayaCash\IdentityVerificationFiles',
            'RayaCash\IdentityVerificationFiles\File',
            'RayaCash\Service\IdentityService',
            'RayaCash\Service\IdentityVerificationService',
            'RayaCash\Service\SandboxHelpersService',
            'RayaCash\Service\SandboxPaymentHelpersService',
        ];
        sort($expected);
        self::assertSame($expected, $new);
    }

    /** @return iterable<string, array{class-string, class-string}> */
    public static function aliases(): iterable
    {
        foreach (self::ALIASES as $old => $new) {
            yield $old => [$old, $new];
        }
    }

    /**
     * El nombre viejo es la misma clase que el nuevo, no una subclase: instanceof, is_a() y los
     * tipos andan para los dos lados.
     *
     * @param class-string $old
     * @param class-string $new
     */
    #[DataProvider('aliases')]
    public function testAnOldNameIsTheSameClassAsTheNewOne(string $old, string $new): void
    {
        self::assertSame($new, (new \ReflectionClass($old))->getName());
        self::assertTrue(is_a($old, $new, true));
        self::assertTrue(is_a($new, $old, true));
        self::assertSame(get_parent_class($new), get_parent_class($old));
    }

    /** @return iterable<string, array{class-string<RayaObject>, \Closure(ConnectOrder): mixed}> */
    public static function objectAliases(): iterable
    {
        yield 'Checkout' => [Checkout::class, static fn(ConnectOrder $order): mixed => $order];
        yield 'Checkout\Item' => [
            Checkout\Item::class,
            static fn(ConnectOrder $order): mixed => $order->items[0],
        ];
        yield 'Checkout\BankTransfer' => [
            Checkout\BankTransfer::class,
            static fn(ConnectOrder $order): mixed => $order->bank_transfer,
        ];
        yield 'Checkout\BackUrls' => [
            Checkout\BackUrls::class,
            static fn(ConnectOrder $order): mixed => $order->back_urls,
        ];
        yield 'Checkout\Payer' => [
            Checkout\Payer::class,
            static fn(ConnectOrder $order): mixed => $order->payer,
        ];
    }

    /**
     * Lo que arma el SDK (un pago de la API, con sus sub-objetos) es instancia de los nombres
     * viejos, y lo que armás con un nombre viejo es de la clase nueva.
     *
     * @param class-string<RayaObject> $old
     * @param \Closure(ConnectOrder): mixed $part
     */
    #[DataProvider('objectAliases')]
    public function testOldObjectNamesBuildAndMatchTheNewObjects(string $old, \Closure $part): void
    {
        $object = $part(ConnectOrder::constructFrom(Fixtures::CONNECT_ORDER_SUCCEEDED));
        self::assertInstanceOf(RayaObject::class, $object);
        self::assertInstanceOf($old, $object);
        $values = $object->toArray();
        $built = $old::constructFrom($values);
        self::assertSame($object::class, $built::class);
        self::assertSame($values, $built->toArray());
    }

    /** `$client->checkout` es `$client->connect`: el mismo objeto, con las rutas nuevas. */
    public function testClientCheckoutIsClientConnect(): void
    {
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        self::assertSame($connected->connect, $connected->checkout);
        self::assertSame($connected->connect->orders, $connected->checkout->orders);
        self::assertInstanceOf(CheckoutService::class, $connected->checkout);
        self::assertInstanceOf(CheckoutOrderService::class, $connected->checkout->orders);

        $this->api->add(201, Fixtures::CONNECT_ORDER_PENDING);
        $checkout = $connected->checkout->orders->create(
            ['items' => [self::ITEM], 'fee' => 500],
            ['idempotency_key' => 'pedido-1042'],
        );
        self::assertSame(ConnectOrder::class, $checkout::class);
        self::assertSame('pending', $checkout->status);
        $request = $this->api->last();
        self::assertSame(['POST', '/v1/connect/orders'], [$request->method, $request->path]);
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $request->header('authorization'));
        self::assertSame('pedido-1042', $request->header('idempotency-key'));
        self::assertSame(['items' => [self::ITEM], 'fee' => 500], $this->api->lastJson());

        $this->api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        $checkout = $connected->checkout->orders->retrieve(Fixtures::CONNECT_ORDER_ID);
        self::assertSame('succeeded', $checkout->status);
        self::assertSame(
            ['GET', '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID],
            [$this->api->last()->method, $this->api->last()->path],
        );

        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $refunded = $connected->checkout->orders->refund(
            Fixtures::CONNECT_ORDER_ID,
            ['idempotency_key' => 'reembolso-1'],
        );
        self::assertSame('refunded', $refunded->status);
        self::assertSame(
            ['POST', '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund'],
            [$this->api->last()->method, $this->api->last()->path],
        );
        self::assertSame('reembolso-1', $this->api->last()->header('idempotency-key'));
        self::assertCount(3, $this->api->requests);

        // Una propiedad readonly no puede avisar al leerse: lleva @deprecated, para tu IDE.
        $property = new \ReflectionProperty(RayaClient::class, 'checkout');
        self::assertTrue($property->isReadOnly());
        self::assertSame(ConnectService::class, (string) $property->getType());
        self::assertStringContainsString(
            '@deprecated Desde la 1.2.0: usá `$client->connect`',
            (string) $property->getDocComment(),
        );
    }

    /** Checkout::create/retrieve/refundById y $checkout->refund(), con el estilo global. */
    public function testCheckoutGlobalStyleCallsTheNewRoutes(): void
    {
        RayaCash::setApiKey(Fixtures::ACCESS_TOKEN);
        RayaCash::setHttpClient($this->api);
        $path = '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID;

        $this->api->add(201, Fixtures::CONNECT_ORDER_PENDING);
        $checkout = Checkout::create(['items' => [self::ITEM], 'fee' => 500]);
        self::assertSame(ConnectOrder::class, $checkout::class);
        $request = $this->api->last();
        self::assertSame(['POST', '/v1/connect/orders'], [$request->method, $request->path]);
        self::assertSame('Bearer ' . Fixtures::ACCESS_TOKEN, $request->header('authorization'));

        $this->api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        $checkout = Checkout::retrieve(Fixtures::CONNECT_ORDER_ID);
        self::assertSame(['GET', $path], [$this->api->last()->method, $this->api->last()->path]);

        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        self::assertSame('refunded', $checkout->refund()->status);
        self::assertSame("{$path}/refund", $this->api->last()->path);

        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $refunded = Checkout::refundById(Fixtures::CONNECT_ORDER_ID, ['idempotency_key' => 'r-2']);
        self::assertSame('refunded', $refunded->status);
        self::assertSame("{$path}/refund", $this->api->last()->path);
        self::assertSame('r-2', $this->api->last()->header('idempotency-key'));
        self::assertCount(4, $this->api->requests);

        // Las constantes son las de ConnectOrder: OBJECT_NAME, con el valor nuevo.
        self::assertSame('connect.order', Checkout::OBJECT_NAME);
        self::assertSame(ConnectOrder::STATUS_SUCCEEDED, Checkout::STATUS_SUCCEEDED);
        // Los errores nombran los métodos nuevos, también llamados con el nombre viejo. PHPStan
        // marca el argumento de más: la declaración de src/Checkout.php no llama a
        // func_num_args(), que vuelve variádico a ConnectOrder::retrieve() para él.
        self::assertThrows(
            \ArgumentCountError::class,
            // @phpstan-ignore arguments.count
            static fn() => Checkout::retrieve('c', [], []),
            'ConnectOrder::retrieve recibe 2 argumentos (orderId y options), no 3',
        );
    }

    /**
     * Un parámetro tipado con el nombre viejo recibe el pago que arma el SDK. PHPStan no ve los
     * alias: para él, Checkout es una subclase de ConnectOrder (la declaración del if (\false)
     * de src/Checkout.php), y lo marca.
     */
    public function testATypeWithTheOldNameTakesTheNewObject(): void
    {
        $this->api->add(200, Fixtures::CONNECT_ORDER_SUCCEEDED);
        $connected = $this->api->client(Fixtures::ACCESS_TOKEN);
        $order = $connected->connect->orders->retrieve(Fixtures::CONNECT_ORDER_ID);
        $handle = static fn(Checkout $checkout): string => $checkout->status;
        // @phpstan-ignore argument.type (PHPStan ve una subclase; en PHP es la misma clase)
        self::assertSame('succeeded', $handle($order));
    }

    /** Las constantes de la 1.1.0 valen lo nuevo: tu handler sigue reconociendo los eventos. */
    public function testTheOldEventTypeConstantsHoldTheNewValues(): void
    {
        foreach (self::EVENT_TYPES as $old => $new) {
            $constant = new \ReflectionClassConstant(Event::class, $old);
            self::assertSame(constant(Event::class . "::{$new}"), $constant->getValue());
            self::assertStringContainsString(
                "@deprecated Desde la 1.2.0: usá {$new}.",
                (string) $constant->getDocComment(),
            );
        }
        self::assertSame('connect.order.succeeded', Event::TYPE_CHECKOUT_SUCCEEDED);

        // El handler de un webhook escrito para la 1.1.0, con un evento de la API de hoy.
        $client = $this->api->client(Fixtures::ACCESS_TOKEN);
        $body = json_encode(Fixtures::EVENT_CONNECT_ORDER_SUCCEEDED, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
        $event = $client->constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
        $object = $event->data->object ?? null;
        self::assertSame(Event::TYPE_CHECKOUT_SUCCEEDED, $event->type);
        self::assertInstanceOf(Checkout::class, $object);
        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        self::assertSame('refunded', $object->refund()->status);
        self::assertSame(
            '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund',
            $this->api->last()->path,
        );
    }

    /**
     * "object": "checkout" (el de un evento de antes del cambio de nombre de la API, si se
     * reenvía) también se arma como ConnectOrder. Su type, checkout.*, no coincide con ninguna
     * constante: las de la 1.1.0 valen lo nuevo.
     */
    public function testTheOldObjectNameStillBuildsAConnectOrder(): void
    {
        $order = [...Fixtures::CONNECT_ORDER_SUCCEEDED, 'object' => 'checkout'];
        $legacy = [
            ...Fixtures::EVENT_CONNECT_ORDER_SUCCEEDED,
            'type' => 'checkout.succeeded',
            'data' => ['object' => $order],
        ];
        $client = $this->api->client(Fixtures::ACCESS_TOKEN);
        $body = json_encode($legacy, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateHeader($body, Fixtures::WEBHOOK_SECRET);
        $event = $client->constructEvent($body, $header, Fixtures::WEBHOOK_SECRET);
        self::assertSame('checkout.succeeded', $event->type);
        self::assertNotSame(Event::TYPE_CHECKOUT_SUCCEEDED, $event->type);
        $object = $event->data->object;
        self::assertSame(ConnectOrder::class, get_debug_type($object));
        self::assertInstanceOf(ConnectOrder::class, $object);
        self::assertSame(['checkout', 'succeeded'], [$object->object, $object->status]);
        self::assertSame(ConnectOrder\Item::class, get_debug_type($object->items[0]));
        // Ligado al cliente, como cualquier otro: el reembolso va a la ruta nueva.
        $this->api->add(200, Fixtures::CONNECT_ORDER_REFUNDED);
        $object->refund();
        self::assertSame(
            '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID . '/refund',
            $this->api->last()->path,
        );

        // Anidado en un objeto sin clase propia y en la respuesta de rawRequest(), igual.
        $nested = RayaObject::constructFrom(['order' => $order])['order'];
        self::assertSame(ConnectOrder::class, get_debug_type($nested));
        $this->api->add(200, $order);
        $raw = $client->rawRequest('get', '/v1/connect/orders/' . Fixtures::CONNECT_ORDER_ID);
        self::assertSame(ConnectOrder::class, $raw::class);
    }

    /**
     * El archivo de cada nombre viejo avisa una vez, silenciado con @, y carga la clase nueva. Se
     * puede volver a cargar: su clase está en un if (\false), para Composer, tu IDE y PHPStan.
     *
     * @param class-string $old
     * @param class-string $new
     */
    #[DataProvider('aliases')]
    public function testTheFileOfAnOldNameWarnsOnce(string $old, string $new): void
    {
        $file = dirname(__DIR__) . '/src/'
            . strtr(substr($old, strlen('RayaCash\\')), '\\', '/') . '.php';
        self::assertFileExists($file);
        $warnings = self::warnings(static function () use ($file): void {
            require $file;
        });
        self::assertSame([[E_USER_DEPRECATED, self::message($old, $new), true]], $warnings);
        $source = (string) file_get_contents($file);
        self::assertStringContainsString("if (\\false) {\n", $source);
        self::assertStringContainsString('@deprecated Desde la 1.2.0: usá ', $source);
    }

    /**
     * Si la clase nueva ya estaba cargada sin su alias (opcache.preload con opcache_compile_file()
     * no corre el final de su archivo) y nadie lo registró (sin el autoload de Composer, que lo
     * hace con src/aliases.php), alias() lo registra igual.
     */
    public function testAliasRegistersTheOldNameIfTheNewClassDidNot(): void
    {
        $old = self::class . '\Precargada' . bin2hex(random_bytes(4));
        self::assertFalse(class_exists($old, false));
        $warnings = self::warnings(static fn() => Deprecation::alias($old, ConnectOrder::class));
        self::assertSame(
            [[E_USER_DEPRECATED, self::message($old, ConnectOrder::class), true]],
            $warnings,
        );
        self::assertTrue(is_a($old, ConnectOrder::class, true));
        self::assertTrue(is_a(ConnectOrder::class, $old, true));
    }

    /**
     * En un proceso nuevo: el primer uso de un nombre viejo, antes que el nuevo, carga su archivo
     * y avisa una vez; los siguientes, nada.
     */
    public function testUsingAnOldNameFirstWarnsOnceInAFreshProcess(): void
    {
        $output = self::runFresh(<<<'PHP'
            $result = [];
            foreach (ALIASES as $old => $new) {
                $before = count($warnings);
                $loaded = class_exists($old);
                $same = (new ReflectionClass($old))->getName() === $new;
                $again = class_exists($old);
                $result[$old] = [$loaded && $same && $again, array_slice($warnings, $before)];
            }
            // Con el código de la 1.1.0: el alias ya está, sin más avisos.
            $checkout = RayaCash\Checkout::constructFrom(['object' => 'checkout', 'id' => 'c']);
            $result['después'] = [$checkout::class, count($warnings)];
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            PHP);
        $expected = [];
        foreach (self::ALIASES as $old => $new) {
            $expected[$old] = [true, [[E_USER_DEPRECATED, self::message($old, $new), true]]];
        }
        $expected['después'] = [ConnectOrder::class, count(self::ALIASES)];
        self::assertSame($expected, json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * En un proceso nuevo, con el código de la 1.1.0 que nunca nombra una clase vieja antes que
     * la nueva: instanceof y los tipos andan sin cargar ningún archivo viejo (PHP no carga clases
     * para eso), porque las clases nuevas registran sus alias al cargar. Sin avisos.
     */
    public function testOldNamesWorkWithoutLoadingTheirFilesInAFreshProcess(): void
    {
        $response = var_export(json_encode(Fixtures::CONNECT_ORDER_SUCCEEDED), true);
        $output = self::runFresh(<<<PHP
            use RayaCash\\HttpClient\\Request;
            use RayaCash\\HttpClient\\Response;

            \$fake = new class implements RayaCash\\HttpClient\\HttpClientInterface {
                public function send(Request \$request): Response
                {
                    return new Response(200, ['Content-Type' => 'application/json'], {$response});
                }
            };
            function legacy(RayaCash\\Checkout \$checkout): string
            {
                return \$checkout->status;
            }
            \$client = new RayaCash\\RayaClient('raya_at_live_x', ['http_client' => \$fake]);
            \$checkout = \$client->checkout->orders->retrieve('e7b3a9c2');
            echo json_encode([
                \$checkout instanceof RayaCash\\Checkout,
                legacy(\$checkout),
                \$checkout->items[0] instanceof RayaCash\\Checkout\\Item,
                \$checkout->bank_transfer instanceof RayaCash\\Checkout\\BankTransfer,
                \$checkout->back_urls instanceof RayaCash\\Checkout\\BackUrls,
                \$checkout->payer instanceof RayaCash\\Checkout\\Payer,
                \$client->checkout instanceof RayaCash\\Service\\CheckoutService,
                \$client->checkout->orders instanceof RayaCash\\Service\\CheckoutOrderService,
                \$warnings,
                array_values(array_filter(
                    get_included_files(),
                    static fn(string \$file): bool => str_contains(\$file, '/src/Checkout')
                        || str_contains(\$file, '/Service/Checkout'),
                )),
            ], JSON_THROW_ON_ERROR);
            PHP);
        self::assertSame(
            [true, 'succeeded', true, true, true, true, true, true, [], []],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * src/aliases.php va en autoload.files: el autoload de Composer lo carga en cada request,
     * antes que cualquier clase. No carga ninguna clase ni avisa nada, y se puede volver a cargar.
     */
    public function testComposerLoadsTheAliasFileWithoutLoadingAnyClass(): void
    {
        self::assertSame(['src/aliases.php'], self::autoloadFiles());
        $file = (string) realpath(dirname(__DIR__) . '/src/aliases.php');
        $output = self::runFresh(strtr(<<<'PHP'
            $sdk = array_filter(
                [...get_declared_classes(), ...get_declared_interfaces()],
                static fn(string $name): bool => str_starts_with($name, 'RayaCash\\'),
            );
            echo json_encode(
                [in_array(ALIAS_FILE, get_included_files(), true), array_values($sdk), $warnings],
                JSON_THROW_ON_ERROR,
            );
            PHP, ['ALIAS_FILE' => var_export($file, true)]));
        self::assertSame([true, [], []], json_decode($output, true, 512, JSON_THROW_ON_ERROR));

        // Este proceso ya lo cargó (tests/bootstrap.php): otra vez, no cambia nada.
        self::assertContains($file, get_included_files());
        $classes = get_declared_classes();
        $warnings = self::warnings(static function () use ($file): void {
            require $file;
        });
        self::assertSame([[], $classes], [$warnings, get_declared_classes()]);
    }

    /**
     * Con opcache.preload, un script que compila src/ con opcache_compile_file() deja declaradas
     * las clases nuevas en cada request sin correr el final de su archivo, su class_alias(). Los
     * registra src/aliases.php al cargar el autoload de Composer: el código de la 1.1.0 anda
     * igual (instanceof y los tipos con los nombres viejos), sin avisos.
     */
    public function testTheAliasesExistWhenOpcachePreloadCompiledTheNewClasses(): void
    {
        if (!extension_loaded('Zend OPcache') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('opcache.preload necesita OPcache, y no anda en Windows.');
        }
        $preload = sys_get_temp_dir() . '/rayacash-preload-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($preload, strtr(<<<'PHP'
            <?php
            // Compila cada archivo de src/ sin correr ninguno, como tantos scripts de precarga.
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(SRC, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    opcache_compile_file($file->getPathname());
                }
            }
            PHP, ['SRC' => var_export(dirname(__DIR__) . '/src', true)]));
        $code = strtr(<<<'PHP'
            <?php
            declare(strict_types=1);

            use RayaCash\HttpClient\Request;
            use RayaCash\HttpClient\Response;

            $warnings = [];
            set_error_handler(static function (int $level, string $text) use (&$warnings): bool {
                $warnings[] = [$level, $text];
                return true;
            });
            // Antes del autoload: las clases nuevas están (precargadas) y sus alias no.
            $before = [];
            foreach (ALIASES as $old => $new) {
                $before[] = [class_exists($new, false), class_exists($old, false)];
            }
            require AUTOLOAD;
            $after = [];
            foreach (ALIASES as $old => $new) {
                $after[] = class_exists($old, false)
                    && (new ReflectionClass($old))->getName() === $new;
            }
            $fake = new class implements RayaCash\HttpClient\HttpClientInterface {
                public function send(Request $request): Response
                {
                    return new Response(200, ['Content-Type' => 'application/json'], BODY);
                }
            };
            function legacy(RayaCash\Checkout $checkout): string
            {
                return $checkout->status;
            }
            $client = new RayaCash\RayaClient('raya_at_live_x', ['http_client' => $fake]);
            $checkout = $client->checkout->orders->retrieve('e7b3a9c2');
            echo json_encode([
                $before,
                $after,
                $checkout instanceof RayaCash\Checkout,
                legacy($checkout),
                $checkout->items[0] instanceof RayaCash\Checkout\Item,
                $checkout->payer instanceof RayaCash\Checkout\Payer,
                $client->checkout->orders instanceof RayaCash\Service\CheckoutOrderService,
                $warnings,
            ], JSON_THROW_ON_ERROR);
            PHP, [
            'ALIASES' => var_export(self::ALIASES, true),
            'AUTOLOAD' => var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            'BODY' => var_export(json_encode(Fixtures::CONNECT_ORDER_SUCCEEDED), true),
        ]);
        try {
            [$status, $output] = self::php(
                [
                    '-d', 'opcache.enable_cli=1',
                    '-d', "opcache.preload={$preload}",
                    // Solo cuenta si los tests corren como root (como en scripts/dev.sh).
                    '-d', 'opcache.preload_user=root',
                    '-d', 'xdebug.mode=off',
                ],
                $code,
            );
        } finally {
            unlink($preload);
        }
        self::assertSame(0, $status, $output);
        $count = count(self::ALIASES);
        self::assertSame(
            [
                array_fill(0, $count, [true, false]),
                array_fill(0, $count, true),
                true,
                'succeeded',
                true,
                true,
                true,
                [],
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Sin un handler propio, el aviso no se ve: PHP no muestra ni loguea un error silenciado con
     * @, ni con display_errors y todos los niveles prendidos.
     */
    public function testTheWarningIsSilentWithoutAHandler(): void
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        [$status, $output] = self::php(
            [
                '-d', 'display_errors=1',
                '-d', 'log_errors=1',
                '-d', 'error_log=',
                '-d', 'error_reporting=-1',
                '-d', 'xdebug.mode=off',
            ],
            "<?php require {$autoload}; echo RayaCash\\Checkout::OBJECT_NAME;",
        );
        self::assertSame([0, 'connect.order'], [$status, $output]);
    }

    // --- Ayudas -------------------------------------------------------------------------------

    private static function message(string $old, string $new): string
    {
        return "La clase {$old} está deprecada desde la 1.2.0: usá {$new}, que es la misma clase. "
            . 'Se elimina en la 2.0.0.';
    }

    /**
     * Los errores de PHP que dispara $fn: [nivel, mensaje, si venía silenciado con @].
     *
     * @return list<array{int, string, bool}>
     */
    private static function warnings(callable $fn): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message, (error_reporting() & $level) === 0];
            return true;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    /**
     * $code en un PHP nuevo, con el autoload del repo, la constante ALIASES y $warnings: los
     * errores que dispara (también el autoload), como en warnings(). Devuelve lo que escribió (y
     * falla si no terminó bien).
     */
    private static function runFresh(string $code): string
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $aliases = var_export(self::ALIASES, true);
        $prelude = <<<PHP
            <?php
            declare(strict_types=1);
            \$warnings = [];
            set_error_handler(static function (int \$level, string \$text) use (&\$warnings): bool {
                \$warnings[] = [\$level, \$text, (error_reporting() & \$level) === 0];
                return true;
            });
            require {$autoload};
            const ALIASES = {$aliases};

            PHP;
        [$status, $output] = self::php(['-d', 'xdebug.mode=off'], $prelude . $code);
        self::assertSame(0, $status, $output);
        return $output;
    }

    /**
     * Las clases públicas de src/ (sin Internal\), según la ruta de cada archivo (PSR-4). Los
     * archivos de autoload.files (src/aliases.php) no son clases.
     *
     * @return list<string>
     */
    private static function publicClassesInSrc(): array
    {
        $root = dirname(__DIR__);
        $src = "{$root}/src";
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );
        $notClasses = array_map(
            static fn(string $file): string => "{$root}/{$file}",
            self::autoloadFiles(),
        );
        $classes = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (in_array($file->getPathname(), $notClasses, true)) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($src) + 1, -4);
            if (!str_starts_with($relative, 'Internal/')) {
                $classes[] = 'RayaCash\\' . strtr($relative, '/', '\\');
            }
        }
        sort($classes);
        return $classes;
    }

    /**
     * Lo que el autoload de Composer carga en cada request (autoload.files de composer.json),
     * relativo a la raíz del repo.
     *
     * @return list<string>
     */
    private static function autoloadFiles(): array
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($composer);
        $autoload = $composer['autoload'] ?? null;
        self::assertIsArray($autoload);
        $files = $autoload['files'] ?? [];
        self::assertIsArray($files);
        $list = [];
        foreach ($files as $file) {
            self::assertIsString($file);
            $list[] = $file;
        }
        return $list;
    }
}
