<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Balance;
use RayaCash\ConnectOrder;
use RayaCash\ConnectPayout;
use RayaCash\Event;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\IdentityVerification;
use RayaCash\IdentityVerificationFiles;
use RayaCash\Invoice;
use RayaCash\ListObject;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\PspBalance;
use RayaCash\PspCustomer;
use RayaCash\PspCustomerBalance;
use RayaCash\PspDocumentRequest;
use RayaCash\PspTransfer;
use RayaCash\RayaClient;
use RayaCash\RayaObject;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\User;

/**
 * Los ejemplos del README, de examples/ y de scripts/, como test_readme.py en Python y
 * readme.test.ts en Node: cada bloque ```php compila, el de «Tests de tu integración» corre de
 * verdad (sin red: usa un cliente HTTP falso) y lo que nombran del SDK existe.
 */
final class ReadmeTest extends TestCase
{
    /** El cliente falso del único bloque que corre (como `fetch: fakeFetch` en Node). */
    private const OFFLINE_MARKER = '$fakeHttpClient';

    /** Identity.js con un <script>, desde el CDN de Raya: v1 es siempre la última 1.x. */
    private const IDENTITY_JS_SCRIPT = '<script src="https://cdn.raya.cash/identity/v1/'
        . 'identity.js"></script>';

    /**
     * Qué es cada variable en los bloques del README y en examples/: así se comprueba que los
     * métodos y los campos que usan existen ($e es cualquier excepción del SDK y $object, el
     * data->object de un evento).
     */
    private const VARIABLES = [
        'client' => [RayaClient::class],
        'connected' => [RayaClient::class],
        'balance' => [Balance::class],
        'payment' => [Payment::class],
        'transfer' => [Transfer::class],
        'deposit' => [Transfer::class],
        'token' => [OAuthToken::class],
        'user' => [User::class],
        'connectOrder' => [ConnectOrder::class],
        'connectPayout' => [ConnectPayout::class],
        'verification' => [IdentityVerification::class],
        'files' => [IdentityVerificationFiles::class],
        'pspCustomer' => [PspCustomer::class],
        'customerBalance' => [PspCustomerBalance::class],
        'pspTransfer' => [PspTransfer::class],
        'pspBalance' => [PspBalance::class],
        'invoice' => [Invoice::class],
        'message' => [Invoice\ArcaMessage::class],
        'object' => [
            Payment::class,
            Transfer::class,
            ConnectOrder::class,
            PspCustomer::class,
            PspTransfer::class,
            PspDocumentRequest::class,
            Invoice::class,
        ],
        'page' => [ListObject::class],
        'event' => [Event::class],
        'e' => [
            RayaException::class,
            ApiConnectionException::class,
            AuthenticationException::class,
            InvalidRequestException::class,
            SignatureVerificationException::class,
        ],
    ];

    /**
     * Las variables de los ejemplos que no son del SDK (tu pedido, el request de tu framework…):
     * no se miran. Una que no está acá ni en VARIABLES falla: así ninguna queda sin mirar.
     */
    private const FOREIGN = [
        'this',
        'request',
        'middleware',
        'order',
        'payout',
        'refund',
        'start',
        'today',
        'account',
        'customer',
        'withdrawal',
    ];

    /** Los 7 códigos de error de Raya PSP: van en la tabla de «Errores». */
    private const PSP_CODES = [
        'PSP_NOT_ACTIVATED',
        'CUSTOMER_NOT_FOUND',
        'CUSTOMER_ALREADY_EXISTS',
        'CUSTOMER_STATUS_CONFLICT',
        'CUSTOMER_INSUFFICIENT_BALANCE',
        'CUSTOMER_DATA_MISMATCH',
        'TAX_ID_NOT_REGISTERED',
    ];

    /** Los 12 eventos de Raya PSP: van en «Webhooks». */
    private const PSP_EVENTS = [
        'psp.customer.activated',
        'psp.customer.updated',
        'psp.customer.failed',
        'psp.customer.rejected',
        'psp.customer.blocked',
        'psp.customer.unblocked',
        'psp.customer.closed',
        'psp.transfer.received',
        'psp.transfer.sent',
        'psp.transfer.failed',
        'psp.document_request.created',
        'psp.document_request.closed',
    ];

    /** Los 2 códigos de error del Facturador: van en la tabla de «Errores». */
    private const FACTURADOR_CODES = ['FACTURADOR_NOT_READY', 'INVOICE_NOT_FOUND'];

    /** Los 3 eventos del Facturador: van en «Webhooks». */
    private const INVOICE_EVENTS = ['invoice.issued', 'invoice.rejected', 'invoice.failed'];

    /** Los motivos de FACTURADOR_NOT_READY (data.reason). */
    private const NOT_READY_REASONS = [
        'not_configured',
        'checking',
        'action_required',
        'certificate_expired',
        'disabled',
    ];

    public function testTheReadmeHasPhpExamples(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::blocks()));
    }

    /** @return iterable<string, array{string}> */
    public static function readmeBlocks(): iterable
    {
        foreach (self::blocks() as $block) {
            yield "README.md:{$block['line']}" => [$block['code']];
        }
    }

    #[DataProvider('readmeBlocks')]
    public function testReadmeBlockCompiles(string $code): void
    {
        // Sin «<?php», php -l no ve código: a los bloques que no lo traen se lo sumamos en la
        // misma línea, así el número de línea de un error es el del bloque.
        $source = str_starts_with($code, '<?php') ? $code : '<?php ' . $code;
        [$status, $output] = self::php(['-l'], $source);
        self::assertSame(0, $status, $output);
    }

    public function testTheOfflineTestingExampleRuns(): void
    {
        $blocks = array_values(array_filter(
            self::blocks(),
            static fn(array $block): bool => str_contains($block['code'], self::OFFLINE_MARKER),
        ));
        self::assertCount(1, $blocks, 'Un solo bloque del README usa ' . self::OFFLINE_MARKER);
        $block = $blocks[0];

        // Es el de «Tests de tu integración».
        $readme = self::readme();
        $section = strpos($readme, "\n## Tests de tu integración\n");
        self::assertNotFalse($section);
        $next = strpos($readme, "\n## ", $section + 1);
        self::assertGreaterThan($section, $block['offset']);
        self::assertLessThan($next === false ? PHP_INT_MAX : $next, $block['offset']);

        // Corre en otro proceso, con el autoload del repo y sin poder salir a la red: el cliente
        // HTTP propio del SDK falla, como en estos tests (además de los proxies y del
        // --network none).
        $prepend = tempnam(sys_get_temp_dir(), 'rayacash-readme-');
        self::assertNotFalse($prepend);
        try {
            file_put_contents($prepend, implode("\n", [
                '<?php',
                'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';',
                'RayaCash\Internal\Transport::$factory = static function (): never {',
                '    throw new RuntimeException(' . var_export(self::NO_NETWORK, true) . ');',
                '};',
            ]));
            // assert() prendido, y sin los avisos de Xdebug (si está cargado) en la salida.
            [$status, $output] = self::php(
                [
                    '-d', 'zend.assertions=1',
                    '-d', 'xdebug.mode=off',
                    '-d', "auto_prepend_file={$prepend}",
                ],
                '<?php ' . $block['code'],
            );
        } finally {
            unlink($prepend);
        }
        self::assertSame([0, ''], [$status, $output]);
    }

    public function testTheDocblocksSayWeForWebhookDestinations(): void
    {
        // Los destinos de Developers › Webhooks son we_… (whe_ fue el prefijo de un borrador).
        $files = self::sourceFiles(dirname(__DIR__) . '/src');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringNotContainsString('whe_', (string) file_get_contents($file), $file);
        }
        self::assertStringNotContainsString('whe_', self::readme());
    }

    public function testIdentityJsLoadsWithNpmOrTheRayaCdn(): void
    {
        // Identity.js con npm (primero) o con el <script> del CDN de Raya, y la CSP de cada
        // forma; nunca un CDN de terceros ni una copia en los estáticos del sitio. Del CDN, solo
        // el origen (en la CSP), v1 o una versión fija.
        $readme = self::readme();
        $start = strpos($readme, "\n## Identidad\n");
        self::assertNotFalse($start);
        $end = strpos($readme, "\n## ", $start + 1);
        $section = substr($readme, $start, $end === false ? null : $end - $start);
        $npm = strpos($section, 'npm install rayacash-identity');
        $script = strpos($section, self::IDENTITY_JS_SCRIPT);
        self::assertNotFalse($npm);
        self::assertNotFalse($script);
        self::assertLessThan($script, $npm, 'npm va antes que el <script> del CDN');
        self::assertStringContainsString('`frame-src https://raya.cash`', $section);
        self::assertStringContainsString('`https://cdn.raya.cash` a `script-src`', $section);
        preg_match_all('~https?://js\.raya\.cash[^\s"\'`)<>]*~', $readme, $urls);
        foreach ($urls[0] as $url) {
            self::assertMatchesRegularExpression(
                '~\Ahttps://js\.raya\.cash(/identity/v(1|\d+\.\d+\.\d+)/identity\.js)?\z~',
                $url,
            );
        }
        self::assertDoesNotMatchRegularExpression(
            '~unpkg|jsdelivr|esm\.sh|skypack|cdnjs~i',
            $readme,
        );
        self::assertDoesNotMatchRegularExpression(
            '~archivos estáticos|tus estáticos|node_modules/rayacash-identity/dist~u',
            $readme,
        );
    }

    public function testTheSandboxSectionIsConditionedAndSaysWhatTripsPeopleUp(): void
    {
        // El README termina en las guías para IA: «Sandbox» va condicionada a que la cuenta lo
        // tenga, y la línea de Identidad de «Antes de empezar» manda a esa sección.
        $readme = self::readme();
        $start = strpos($readme, "\n## Sandbox\n");
        $end = strpos($readme, "\n## Tests de tu integración\n");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $sandbox = substr($readme, $start, $end - $start);
        foreach (
            [
                'Si en el menú de tu perfil del dashboard aparece «Crear sandbox» o «Sandboxes»',
                'Si no aparece, tu cuenta todavía no lo tiene.',
                // Un evento sale solo hacia los destinos que ya existían cuando ocurrió.
                'antes de simular',
                'después de crearlo',
                // Al pasar a la cuenta real cambian la clave y el secreto del destino.
                '`RAYA_WEBHOOK_SECRET`',
                // Una orden simulada se consulta con el access_token, no con la clave secreta.
                '$connected->connect->orders->retrieve()',
            ] as $text
        ) {
            self::assertStringContainsString($text, $sandbox);
        }
        self::assertMatchesRegularExpression(
            '/^7\. \*\*Para verificar la identidad.*disponible en el \[sandbox\]\(#sandbox\):/mu',
            $readme,
        );
    }

    /**
     * «Raya PSP», entre Identidad y Facturador: el índice, el link a la referencia, el flujo en
     * orden y ningún precio; los 7 códigos en «Errores» y los 12 eventos en «Webhooks».
     */
    public function testThePspSectionTellsTheFlowTheErrorsAndTheEvents(): void
    {
        $readme = self::readme();
        self::assertStringContainsString(
            "- [Identidad](#identidad)\n- [Raya PSP](#raya-psp)\n- [Facturador](#facturador)\n",
            $readme,
        );
        $psp = self::section($readme, 'Raya PSP');
        self::assertStringContainsString('https://raya.cash/api/psp', $psp);
        self::assertStringContainsString('`$client->psp` existe desde la 1.3.0.', $psp);
        $position = 0;
        foreach (
            [
                'psp->customers->create(',
                'psp.customer.activated',
                'psp->customers->retrieve(',
                'psp->transfers->create(',
                'psp.transfer.received',
                'psp->customers->block(',
                'psp->customers->list(',
            ] as $step
        ) {
            $found = strpos($psp, $step);
            self::assertNotFalse($found, $step);
            self::assertGreaterThan($position, $found, "{$step} va después del paso anterior");
            $position = $found;
        }
        // Sin precios: la comisión no se escribe, y ningún monto lleva signo de pesos.
        self::assertDoesNotMatchRegularExpression('/\$\s?\d/', $psp);
        self::assertStringContainsString('`INSUFFICIENT_BALANCE`', $psp);
        self::assertStringContainsString('`CUSTOMER_INSUFFICIENT_BALANCE`', $psp);
        $errors = self::section($readme, 'Errores');
        foreach (self::PSP_CODES as $code) {
            self::assertStringContainsString("`{$code}`", $errors, $code);
            self::assertStringContainsString("`{$code}`", $psp, $code);
        }
        $webhooks = self::section($readme, 'Webhooks');
        foreach (self::PSP_EVENTS as $type) {
            self::assertStringContainsString("`{$type}`", $webhooks, $type);
            self::assertStringContainsString("`{$type}`", $psp, $type);
        }
        // En el sandbox todavía no está.
        self::assertStringContainsString(
            'Identidad, Raya PSP (`psp`) y el Facturador (`invoices`',
            self::section($readme, 'Sandbox'),
        );
    }

    /**
     * «Facturador», entre Raya PSP y Sandbox: el índice, el link a la referencia, desde qué
     * versión existe, el flujo en orden (configurar en el dashboard, crear, el webhook, leer y
     * listar), solo en la cuenta real y ningún precio; los 2 códigos en «Errores» (con los motivos
     * de FACTURADOR_NOT_READY en la sección), los 3 eventos en «Webhooks» y la clave obligatoria
     * en «Idempotencia».
     */
    public function testTheFacturadorSectionTellsTheFlowTheErrorsAndTheEvents(): void
    {
        $readme = self::readme();
        self::assertStringContainsString(
            "- [Raya PSP](#raya-psp)\n- [Facturador](#facturador)\n- [Sandbox](#sandbox)\n",
            $readme,
        );
        $facturador = self::section($readme, 'Facturador');
        self::assertLessThan(
            strpos($readme, "\n## Sandbox\n"),
            strpos($readme, "\n## Facturador\n"),
        );
        self::assertGreaterThan(
            strpos($readme, "\n## Raya PSP\n"),
            strpos($readme, "\n## Facturador\n"),
        );
        self::assertStringContainsString('https://raya.cash/api/invoices', $facturador);
        self::assertStringContainsString(
            '`$client->invoices` existe desde la 1.4.0.',
            $facturador,
        );
        $position = 0;
        foreach (
            [
                'Negocios › Facturador',
                'invoices->create(',
                'invoice.issued',
                'invoices->retrieve(',
                'invoices->list(',
            ] as $step
        ) {
            $found = strpos($facturador, $step);
            self::assertNotFalse($found, $step);
            self::assertGreaterThan($position, $found, "{$step} va después del paso anterior");
            $position = $found;
        }
        // Lo que se configura en el dashboard, y la clave obligatoria.
        foreach (
            [
                'tu punto de venta de ARCA',
                'tu certificado de ARCA y su clave privada',
                'tu condición frente al IVA',
                'el concepto',
                'La `idempotency_key` es obligatoria',
                'responde `202` con la factura `pending`',
                "['dni' => '12345678', 'amount' => 15000",
            ] as $text
        ) {
            self::assertStringContainsString($text, $facturador, $text);
        }
        // Solo en la cuenta real: con una clave de prueba, 403.
        self::assertStringContainsString('`SANDBOX_NOT_SUPPORTED`', $facturador);
        self::assertStringContainsString(
            'el Facturador (`invoices`',
            self::section($readme, 'Sandbox'),
        );
        // Sin precios: ningún monto lleva signo de pesos.
        self::assertDoesNotMatchRegularExpression('/\$\s?\d/', $facturador);
        $errors = self::section($readme, 'Errores');
        foreach (self::FACTURADOR_CODES as $code) {
            self::assertStringContainsString("`{$code}`", $errors, $code);
            self::assertStringContainsString("`{$code}`", $facturador, $code);
        }
        foreach (self::NOT_READY_REASONS as $reason) {
            self::assertStringContainsString("`{$reason}`", $facturador, $reason);
        }
        $webhooks = self::section($readme, 'Webhooks');
        foreach (self::INVOICE_EVENTS as $type) {
            self::assertStringContainsString("`{$type}`", $webhooks, $type);
            self::assertStringContainsString("`{$type}`", $facturador, $type);
        }
        self::assertStringContainsString(
            '`psp->transfers->create` e `invoices->create` exigen la `idempotency_key`',
            self::section($readme, 'Idempotencia'),
        );
    }

    /**
     * Los `use` de cada ejemplo van juntos, sin líneas en blanco entre ellos, como en la doc web
     * (/api) y en los README de Python y Node.
     */
    public function testTheImportsOfEachExampleGoTogether(): void
    {
        foreach (self::blocks() as $block) {
            $header = [];
            foreach (explode("\n", $block['code']) as $line) {
                if (str_starts_with($line, 'use ')) {
                    $header[] = $line;
                } elseif ($header !== [] && trim($line) === '') {
                    $header[] = '';
                } elseif ($header !== []) {
                    break;
                }
            }
            while ($header !== [] && $header[array_key_last($header)] === '') {
                array_pop($header);
            }
            self::assertNotContains(
                '',
                $header,
                "README.md:{$block['line']} separa sus use:\n" . implode("\n", $header),
            );
        }
    }

    /**
     * Los status de recreate, como los devuelve la API: 200 con active o failed y 202 con
     * pending. El 201 es solo del alta (create).
     */
    public function testThePspSectionGivesRecreateTheStatusesOfTheApi(): void
    {
        $psp = self::section(self::readme(), 'Raya PSP');
        self::assertStringContainsString(
            '| `POST /v1/psp/customers/:id/recreate` | `PspCustomer`: `active` o `failed` '
                . '(HTTP 200), `pending` (HTTP 202) |',
            $psp,
        );
        self::assertStringContainsString(
            'y su historial, y responde `200` con `active` o `failed`, o `202` con `pending`.',
            $psp,
        );
        self::assertStringContainsString(
            '| `POST /v1/psp/customers` | `PspCustomer`: `active` o `failed` (HTTP 201), '
                . '`verifying` o `pending` (HTTP 202) |',
            $psp,
        );
    }

    /**
     * Crear un cliente tiene tres formas (A7): con Raya Identity va solo identity_verification (el
     * ejemplo no manda ni tax_id ni name ni person_type), con las fotos van los datos y las tres
     * URLs (sin gender: el sexo sale del DNI) y una persona jurídica va sin fotos. Por eso
     * CUSTOMER_DATA_MISMATCH queda solo para la razón social y CUSTOMER_ALREADY_EXISTS también
     * vale para una verificación que ya usaste.
     */
    public function testThePspSectionTellsTheThreeWaysToCreateACustomer(): void
    {
        $readme = self::readme();
        $psp = self::section($readme, 'Raya PSP');
        foreach (
            [
                '| Se verificó con Raya Identity | Solo `identity_verification` |',
                '| Es una persona humana y lo verificaste vos (tu propio KYC o el de otro '
                    . 'proveedor) | `tax_id`, `name`, `person_type` `F`, `document_front_url`, '
                    . '`document_back_url` y `selfie_url` |',
                '| Es una persona jurídica | `tax_id`, `name` y `person_type` `J` |',
                "\$pspCustomer = \$client->psp->customers->create(\n    [\n"
                    . "        'identity_verification' => 'idv_3kT9vQ2mXw7Lp0aBcD4eF6gH',\n"
                    . "        'external_reference' => 'cliente-8812', // tu id del cliente, sin "
                    . "datos personales\n"
                    . "        'metadata' => ['plan' => 'pro'],\n    ],\n",
                'si la razón social no coincide, `CUSTOMER_DATA_MISMATCH`, con `name` en '
                    . "`\$e->getData()['field']`",
                'Si ya tenés un cliente con ese CUIL o CUIT, o con esa verificación, '
                    . '`CUSTOMER_ALREADY_EXISTS`',
            ] as $text
        ) {
            self::assertStringContainsString($text, $psp, $text);
        }
        self::assertStringNotContainsString("'tax_id' =>", $psp);
        self::assertStringNotContainsStringIgnoringCase('gender', $readme);
    }

    public function testTheSourcesDoNotNameOtherProviders(): void
    {
        // Los docblocks salen con el paquete (tu IDE los muestra). El nombre va armado, así el
        // grep del repo sigue limpio.
        $competitor = 'str' . 'ipe';
        $files = self::sourceFiles(dirname(__DIR__) . '/src');
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringNotContainsStringIgnoringCase(
                $competitor,
                (string) file_get_contents($file),
                $file,
            );
        }
        self::assertStringNotContainsStringIgnoringCase($competitor, self::readme());
    }

    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        foreach (['examples', 'scripts'] as $folder) {
            foreach (glob(dirname(__DIR__) . "/{$folder}/*.php") ?: [] as $file) {
                yield "{$folder}/" . basename($file) => [$file];
            }
        }
    }

    #[DataProvider('phpFiles')]
    public function testExamplesAndScriptsCompile(string $file): void
    {
        [$status, $output] = self::php(['-l', $file]);
        self::assertSame(0, $status, $output);
    }

    /** @return iterable<string, array{string}> */
    public static function snippets(): iterable
    {
        foreach (self::blocks() as $block) {
            yield "README.md:{$block['line']}" => [$block['code']];
        }
        foreach (glob(dirname(__DIR__) . '/examples/*.php') ?: [] as $file) {
            yield 'examples/' . basename($file) => [(string) file_get_contents($file)];
        }
    }

    /**
     * Lo que los ejemplos nombran del SDK existe: las clases que importan, sus métodos estáticos
     * y constantes, y los métodos y campos de $client, $payment, $transfer… (también en los
     * comentarios). Los de Laravel y Symfony no pasan por PHPStan: esto los cubre.
     */
    #[DataProvider('snippets')]
    public function testSnippetsOnlyUseWhatTheSdkHas(string $code): void
    {
        $problems = [];
        // El cliente es $client en todos lados: un $raya viejo falla, también sin ->.
        if (preg_match('/\$raya\b/', $code) === 1) {
            $problems[] = '$raya: el cliente se llama $client';
        }
        $imports = self::sdkImports($code);
        foreach ($imports as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                $problems[] = "{$class} no existe";
            }
        }

        preg_match_all('/\b([A-Z]\w*)::(\w+)(\s*\()?/', $code, $statics, PREG_SET_ORDER);
        foreach ($statics as $match) {
            $class = $imports[$match[1]] ?? null;
            $member = $match[2];
            if ($class === null || $member === 'class' || !class_exists($class)) {
                continue;
            }
            $name = "{$match[1]}::{$member}";
            if (($match[3] ?? '') === '') {
                if (!defined("{$class}::{$member}")) {
                    $problems[] = "{$name} no existe";
                }
            } elseif (!method_exists($class, $member)) {
                $problems[] = "{$name}() no existe";
            } elseif (!(new \ReflectionMethod($class, $member))->isStatic()) {
                $problems[] = "{$name}() no es estático";
            }
        }

        preg_match_all(
            '/\$(\w+)->(\w+)(\s*\()?(?:->(\w+)(\s*\()?)?/',
            $code,
            $chains,
            PREG_SET_ORDER,
        );
        foreach ($chains as $match) {
            $classes = self::VARIABLES[$match[1]] ?? null;
            if ($classes === null) {
                if (!in_array($match[1], self::FOREIGN, true)) {
                    $problems[] = "\${$match[1]}: sumala a VARIABLES (si es del SDK) o a FOREIGN";
                }
                continue;
            }
            $member = $match[2];
            $call = ($match[3] ?? '') !== '';
            $owner = self::owner($classes, $member, $call);
            if ($owner === null) {
                $problems[] = "\${$match[1]}->{$member}" . ($call ? '()' : '') . ' no existe';
                continue;
            }
            // Un nivel más: $client->payments->create(), $payment->bank_transfer->cvu.
            $next = $match[4] ?? '';
            $nested = $call || $next === '' ? null : self::memberClass($owner, $member);
            $nextCall = ($match[5] ?? '') !== '';
            if ($nested !== null && self::owner([$nested], $next, $nextCall) === null) {
                $problems[] = "\${$match[1]}->{$member}->{$next}" . ($nextCall ? '()' : '')
                    . ' no existe';
            }
        }
        self::assertSame([], $problems);
    }

    public function testTheTableOfContentsMatchesTheSections(): void
    {
        $readme = self::readme();
        preg_match_all('/^## (.+)$/m', $readme, $headings);
        $sections = array_values(array_diff($headings[1], ['Contenido']));
        preg_match('/^## Contenido\n\n((?:- .+\n)+)/m', $readme, $toc);
        preg_match_all('/^- \[(.+)\]\(#(.+)\)$/m', $toc[1] ?? '', $entries, PREG_SET_ORDER);
        self::assertSame(
            array_map(static fn(string $title): array => [$title, self::anchor($title)], $sections),
            array_map(static fn(array $entry): array => [$entry[1], $entry[2]], $entries),
        );
    }

    // --- Ayudas -------------------------------------------------------------------------------

    /**
     * Los .php bajo $dir, recursivo.
     *
     * @return list<string>
     */
    private static function sourceFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            if (is_dir($path)) {
                $files = [...$files, ...self::sourceFiles($path)];
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private static function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/README.md');
    }

    /** Una sección del README: desde su «## Título» hasta la siguiente «## ». */
    private static function section(string $readme, string $title): string
    {
        $start = strpos($readme, "\n## {$title}\n");
        self::assertNotFalse($start, "El README no tiene la sección «{$title}».");
        $end = strpos($readme, "\n## ", $start + 1);
        return substr($readme, $start, $end === false ? null : $end - $start);
    }

    /**
     * Los bloques ```php del README: el código, la línea donde empieza y su posición.
     *
     * @return list<array{code: string, line: int, offset: int}>
     */
    private static function blocks(): array
    {
        $readme = self::readme();
        preg_match_all('/^```php\n(.*?)^```$/ms', $readme, $matches, PREG_OFFSET_CAPTURE);
        $blocks = [];
        foreach ($matches[1] as [$code, $offset]) {
            $blocks[] = [
                'code' => $code,
                'line' => substr_count(substr($readme, 0, $offset), "\n") + 1,
                'offset' => $offset,
            ];
        }
        return $blocks;
    }

    /**
     * Las clases del SDK que importa un ejemplo, por el nombre con que las usa.
     *
     * @return array<string, string>
     */
    private static function sdkImports(string $code): array
    {
        preg_match_all(
            '/^use (RayaCash\\\\[\w\\\\]+)(?: as (\w+))?;$/m',
            $code,
            $matches,
            PREG_SET_ORDER,
        );
        $imports = [];
        foreach ($matches as $match) {
            $class = $match[1];
            $alias = ($match[2] ?? '') !== ''
                ? $match[2]
                : substr($class, (int) strrpos($class, '\\') + 1);
            $imports[$alias] = $class;
        }
        return $imports;
    }

    /**
     * La primera de $classes que tiene ese método (con $call) o ese campo: una propiedad de
     * verdad o, en los objetos de la API, un @property-read. null si ninguna.
     *
     * @param list<class-string> $classes
     * @return class-string|null
     */
    private static function owner(array $classes, string $member, bool $call): ?string
    {
        foreach ($classes as $class) {
            $found = $call
                ? method_exists($class, $member)
                : (property_exists($class, $member)
                    || isset(self::documentedFields($class)[$member]));
            if ($found) {
                return $class;
            }
        }
        return null;
    }

    /**
     * La clase de un campo: el tipo de una propiedad (los servicios de RayaClient) o el que
     * documenta el objeto (Payment\BankTransfer…). null si no es una sola clase.
     *
     * @param class-string $class
     * @return class-string|null
     */
    private static function memberClass(string $class, string $member): ?string
    {
        if (property_exists($class, $member)) {
            $type = (new \ReflectionProperty($class, $member))->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            return class_exists($name) ? $name : null;
        }
        $type = str_replace(['|null', 'null|'], '', self::documentedFields($class)[$member] ?? '');
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $nested = "{$namespace}\\{$type}";
        return preg_match('/\A[A-Z][\w\\\\]*\z/', $type) === 1 && class_exists($nested)
            ? $nested
            : null;
    }

    /**
     * Los campos que documenta un objeto de la API (@property-read), con su tipo.
     *
     * @param class-string $class
     * @return array<string, string>
     */
    private static function documentedFields(string $class): array
    {
        if (!is_a($class, RayaObject::class, true)) {
            return [];
        }
        $doc = (string) (new \ReflectionClass($class))->getDocComment();
        preg_match_all('/@property-read\s+([^$]*?)\s*\$(\w+)/', $doc, $matches, PREG_SET_ORDER);
        $fields = [];
        foreach ($matches as $match) {
            $fields[$match[2]] = $match[1];
        }
        return $fields;
    }

    /** El ancla que arma GitHub para un título: minúsculas, sin puntuación y con guiones. */
    private static function anchor(string $title): string
    {
        $slug = (string) preg_replace('/[^\p{L}\p{N}\- ]/u', '', strtolower($title));
        return str_replace(' ', '-', $slug);
    }
}
