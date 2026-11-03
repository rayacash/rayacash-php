# rayacash-php

El SDK oficial de PHP para la [API de Raya Cash](https://raya.cash/api). Te deja cobrar por transferencia con un CVU exclusivo por pago, mandar plata a cualquier CBU, CVU o alias, consultar tu saldo, conciliar tus depósitos y verificar los webhooks, con reintentos seguros e idempotencia incluidos.

```php
use RayaCash\RayaClient;

$client = new RayaClient(); // lee tu clave secreta de RAYA_SECRET_KEY

$payment = $client->payments->create(['amount' => 15000, 'description' => 'Pedido #1042']);
echo $payment->bank_transfer->cvu, ' ', $payment->checkout_url, "\n";
```

Versión 1.0.0. Requiere PHP 8.1 o más nuevo, con las extensiones curl y json. Los cambios de cada versión están en [CHANGELOG.md](CHANGELOG.md).

## Contenido

- [Antes de empezar](#antes-de-empezar)
- [Instalación](#instalación)
- [Inicio rápido](#inicio-rápido)
- [Estilo global](#estilo-global)
- [Laravel, Symfony y WordPress](#laravel-symfony-y-wordpress)
- [Montos](#montos)
- [Idempotencia](#idempotencia)
- [Reintentos y timeouts](#reintentos-y-timeouts)
- [Paginación](#paginación)
- [Errores](#errores)
- [Webhooks](#webhooks)
- [Tests de tu integración](#tests-de-tu-integración)
- [Configuración avanzada](#configuración-avanzada)
- [Seguridad](#seguridad)
- [Versionado y compatibilidad](#versionado-y-compatibilidad)
- [Desarrollo](#desarrollo)

## Antes de empezar

1. **Tu cuenta de Raya con la identidad verificada** (si no, `API_KEY_ACCOUNT_LOCKED`) y la API habilitada (si no, `API_NOT_ENABLED`: escribinos a soporte@raya.cash).
2. **Tu clave secreta** (`raya_sk_live_…`), de [Developers › API Keys](https://raya.cash/dashboard/developers/api-keys). La vas a ver completa una sola vez: guardala en una variable de entorno de tu servidor.
3. **Para cobrar:** tu negocio configurado en [Configuración › Negocio](https://raya.cash/dashboard/settings/business). Sin eso, crear un pago da `MERCHANT_NOT_CONFIGURED`.
4. **Para los webhooks:** un destino en [Developers › Webhooks](https://raya.cash/dashboard/developers/webhooks) y su secreto (`whsec_…`).

## Instalación

```bash
composer require rayacash/rayacash-php
```

No tiene dependencias: usa las extensiones `curl` y `json` de PHP. `json` viene siempre y `curl`, en casi todas las instalaciones (en Debian y Ubuntu es el paquete `php-curl`).

## Inicio rápido

```php
use RayaCash\Payment;
use RayaCash\RayaClient;

require __DIR__ . '/vendor/autoload.php'; // el de Composer (Laravel y Symfony ya lo cargan)

$client = new RayaClient(); // lee RAYA_SECRET_KEY ($_ENV, $_SERVER o getenv())

// Tu saldo disponible, en pesos.
$balance = $client->balance->retrieve();
echo $balance->available, "\n";

// Cobrar: un pago con un CVU y un alias exclusivos.
$payment = $client->payments->create(
    [
        'amount' => 15000,
        'description' => 'Pedido #1042',
        'external_reference' => 'pedido-1042',
        'return_url' => 'https://tienda.example.com/pedidos/1042',
        'metadata' => ['cliente_id' => '8812'],
    ],
    ['idempotency_key' => $order->payment_idempotency_key], // un UUID v4 guardado con el pedido
);
// Mostrá $payment->bank_transfer->cvu y $payment->bank_transfer->alias, o mandá a quien paga a:
echo $payment->checkout_url, "\n";

// Consultar un pago (por ejemplo, cuando quien paga vuelve a return_url).
$payment = $client->payments->retrieve($payment->id);
if ($payment->status === Payment::STATUS_SUCCEEDED) {
    // entregá el pedido
}

// Reembolsar un pago (total, hasta 30 días después de pagado).
$payment = $client->payments->refund($payment->id, ['idempotency_key' => $refund->idempotency_key]);

// Enviar una transferencia a un CBU, CVU o alias. La idempotency_key es obligatoria.
$transfer = $client->transfers->create(
    ['amount' => '25000.50', 'destination' => '0000123380736153041182'],
    ['idempotency_key' => $payout->idempotency_key], // guardala ANTES de llamar
);
// 'pending' (otro banco) o 'completed' (Raya):
echo $transfer->status, ' ', $transfer->counterparty->name, "\n";

// Tus depósitos de un día, página por página.
$page = $client->transfers->list([
    'direction' => 'inbound',
    'created_gte' => '2026-10-23T00:00:00-03:00',
    'created_lt' => '2026-10-24T00:00:00-03:00',
    'limit' => 100,
]);
foreach ($page->autoPagingIter() as $deposit) {
    echo $deposit->id, ' ', $deposit->net_amount, "\n";
}
```

Cada método recibe los parámetros en un array, con los nombres de la API (`external_reference`, `created_gte`), y las opciones de la llamada, como la `idempotency_key`, en otro array: el último argumento. Un parámetro o una opción que no existe falla antes de mandar nada (con «¿quisiste decir…?» si escribiste `externalReference`).

Cada respuesta es un objeto del SDK (`Balance`, `Transfer`, `Payment`, `Event` o `ListObject`): `$payment->status` y `$payment['status']` dan lo mismo, `json_encode($payment)` da el JSON exacto de la API (con el `serialize_precision` de fábrica, `-1`) y `$payment->toArray()`, arrays comunes. Los valores vienen tal como los manda la API: montos `int` o `float` y fechas en string ISO 8601 (`'2026-10-24T14:00:00Z'`). Si la API suma un campo nuevo, lo ves igual (`$payment['campo_nuevo']`). Leer un campo que no existe tira `\OutOfBoundsException`; para uno que puede faltar, `$payment->campo ?? null` e `isset($payment->campo)` nunca fallan. Los estados y tipos tienen constantes (`Payment::STATUS_SUCCEEDED`, `Transfer::DIRECTION_INBOUND`, `Event::TYPE_PAYMENT_SUCCEEDED`…), y pueden sumarse valores: tu código tiene que ignorar los que no conoce.

Cada objeto trae `getLastResponse()`, con el `requestId` (`req_…`) que nos tenés que mandar si escribís a soporte:

```php
echo $payment->getLastResponse()?->requestId;
```

## Estilo global

También podés configurar una clave para todo el proceso y llamar a las clases:

```php
use RayaCash\Balance;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\Transfer;

RayaCash::setApiKey($secretKey); // si no la asignás, se lee de RAYA_SECRET_KEY en cada request

$balance = Balance::retrieve();
$payment = Payment::create(['amount' => 15000, 'description' => 'Pedido #1042']);
$payment = Payment::retrieve($payment->id);
$refunded = $payment->refund(); // o Payment::refundById($payment->id)
$transfer = Transfer::create(
    ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
    ['idempotency_key' => $payout->idempotency_key],
);
$page = Transfer::list(['direction' => 'inbound']);
```

Los dos estilos usan el mismo núcleo y se comportan igual. La configuración global se lee en cada request, así que cambiarla entre llamadas surte efecto enseguida. Cada valor se valida al asignarlo, salvo la clave, que se valida en cada request: así un `.env` vacío no rompe el arranque de tu framework. Preferí `RayaClient` si tu proceso usa más de una clave o si querés configurar cada cliente por separado. `$payment->refund()` usa el cliente del que vino el pago: si lo trajiste con un `RayaClient`, usa ese cliente; si no, la configuración global. PHP no deja tener un `refund()` estático y otro de instancia, por eso el reembolso por id del estilo global es `Payment::refundById()`.

## Laravel, Symfony y WordPress

El SDK no depende de ningún framework. Lo que conviene saber en cada uno:

**Laravel.** Con `php artisan config:cache` (lo normal en producción), Laravel deja de leer el `.env`: fuera de `config/`, `env()` y el SDK solo ven las variables de entorno reales del servidor, así que la `RAYA_SECRET_KEY` de tu `.env` no llega. Por eso la clave va en `config/services.php` y el cliente se arma desde `config()`, uno solo para toda la app:

```php
// config/services.php
return [
    // …
    'raya' => [
        'secret' => env('RAYA_SECRET_KEY'),
        'webhook_secret' => env('RAYA_WEBHOOK_SECRET'),
    ],
];
```

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RayaCash\RayaClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Uno solo, que reusa la conexión. Se arma recién la primera vez que alguien lo pide.
        $this->app->singleton(
            RayaClient::class,
            static fn(): RayaClient => new RayaClient(config('services.raya.secret')),
        );
    }
}
```

Después, pedilo donde lo uses: `public function store(Request $request, RayaClient $client)` en un controller, o `app(RayaClient::class)`. Laravel suma a su log el `context()` de cada excepción del SDK (el código, el request id y el status HTTP).

- **Colas:** nunca guardes el cliente en una propiedad de un job. Los jobs se serializan, y un `RayaClient` no se deja serializar: así la clave nunca termina en tu cola. Pedilo en `handle()`:

```php
// app/Jobs/SendPayout.php
namespace App\Jobs;

use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RayaCash\RayaClient;

class SendPayout implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $payoutId) {}

    public function handle(RayaClient $client): void
    {
        $payout = Payout::findOrFail($this->payoutId);
        $client->transfers->create(
            ['amount' => $payout->amount, 'destination' => $payout->destination],
            ['idempotency_key' => $payout->idempotency_key], // la misma en cada reintento del job
        );
    }
}
```

- **Octane, RoadRunner y FrankenPHP (modo worker):** el mismo proceso atiende muchas requests. Compartir un `RayaClient` entre requests está bien (no guarda nada de cada request y reusa la conexión), pero lo estático también dura: no cambies `RayaCash::setApiKey()` por request ni por cuenta. Si operás con varias cuentas, armá un `RayaClient` por cuenta.

**Symfony.** Registrá el cliente como servicio (es compartido: uno solo) y pedilo por autowiring en tus controllers y en los handlers de Messenger. Como en Laravel, no lo pongas dentro de un mensaje: los mensajes se serializan.

```yaml
# config/services.yaml
services:
    RayaCash\RayaClient:
        arguments:
            $apiKey: '%env(RAYA_SECRET_KEY)%'
```

**WordPress.** Recibí los webhooks con la API REST de WordPress: `get_body()` da el body crudo. La URL del destino queda `https://tu-sitio.com/wp-json/raya/v1/webhook`.

```php
use RayaCash\Event;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Payment;
use RayaCash\Webhook;

add_action('rest_api_init', static function (): void {
    register_rest_route('raya/v1', '/webhook', [
        'methods' => 'POST',
        'permission_callback' => '__return_true', // la firma reemplaza al login
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            try {
                $event = Webhook::constructEvent(
                    $request->get_body(), // el body crudo, sin parsear
                    $request->get_header('Raya-Signature'),
                    RAYA_WEBHOOK_SECRET, // definila en wp-config.php
                );
            } catch (SignatureVerificationException) {
                return new WP_REST_Response('Firma inválida', 400);
            }
            $object = $event->data->object ?? null;
            if ($event->type === Event::TYPE_PAYMENT_SUCCEEDED && $object instanceof Payment) {
                // marcá como pagado el pedido $object->external_reference
            }
            return new WP_REST_Response(null, 200);
        },
    ]);
});
```

La clave secreta también puede ir en `wp-config.php` (fuera del repositorio): `new RayaClient(RAYA_SECRET_KEY)`. Si distribuís tu plugin, prefijá el SDK con [PHP-Scoper](https://github.com/humbug/php-scoper) o [Strauss](https://github.com/BrianHenryIE/strauss), así no choca con la versión que traiga otro plugin (dejá `Psr\Log` sin prefijo si le pasás un logger PSR-3 a `RayaCash::setLogger()`).

## Montos

- La API trabaja en **pesos** (no en centavos), con hasta 2 decimales. `currency` es siempre `'ARS'`.
- **Para mandar** un monto podés pasar un `int`, un `float`, un `string` decimal o un decimal exacto, como `BcMath\Number` (PHP 8.4) o el `BigDecimal` de brick/math: `15000`, `15000.5`, `'15000.50'` o `new BcMath\Number('15000.50')`. Viaja como número JSON (nunca como string).
- **El SDK nunca redondea.** Si mandás más de 2 decimales (por ejemplo, un `float` con ruido como `0.1 + 0.2`), la API responde `PARAMETER_INVALID` y no se hace nada. Si tus montos salen de tu base de datos, pasalos como string (los `DECIMAL` de MySQL y PostgreSQL ya llegan así); si salen de cuentas, redondeá vos antes, por ejemplo con `number_format($amount, 2, '.', '')`.
- **Los montos que recibís** (`amount`, `fee`, `net_amount`, `available`…) llegan como los manda la API: `int` si son redondos y `float` si tienen centavos. Para sumar o comparar con exactitud, pasalos a centavos enteros:

```php
$cents = 0;
foreach ($page as $transfer) {
    $cents += (int) round($transfer->net_amount * 100);
}
echo number_format($cents / 100, 2, '.', ''); // exacto: "48169.59"
```

## Idempotencia

Con una `Idempotency-Key` podés reintentar un POST sin miedo a que se haga dos veces: si ya lo procesamos, te devolvemos el mismo resultado.

- **`transfers->create` exige la `idempotency_key`.** Generá un UUID v4 (`RayaCash\Util\Uuid::v4()`) para cada transferencia, **guardalo con la operación en tu base antes de llamar** y reusalo en cada reintento. Así, si tu proceso se corta después de mandar la request, el reintento no transfiere dos veces.
- En `payments->create` y `payments->refund` es opcional pero recomendada. Si no la pasás, el SDK genera una que protege solo los reintentos de esa llamada.
- El SDK reintenta siempre con la misma clave. La que usó queda en `$obj->getLastResponse()->idempotencyKey` y en `$e->getIdempotencyKey()`, para que puedas reintentar más tarde.
- Guardamos cada clave 24 horas, para toda tu cuenta. Usar la misma clave con otros parámetros da `IdempotencyException` con `getErrorCode() === 'IDEMPOTENCY_KEY_REUSED'`.
- Si una transferencia terminó `failed`, para intentarla de nuevo usá una clave **nueva**: con la misma te devolvemos la respuesta original.

```php
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Util\Uuid;

$key = Uuid::v4();
$payout->saveIdempotencyKey($key); // primero guardala

try {
    $transfer = $client->transfers->create(
        ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
        ['idempotency_key' => $key],
    );
} catch (ApiConnectionException $e) {
    // no sabemos si salió: reintentá más tarde con la MISMA key (nunca se hace dos veces)
}
```

## Reintentos y timeouts

El SDK reintenta solo lo que es seguro reintentar, siempre con la misma `Idempotency-Key`:

| Respuesta | ¿Reintenta? | Espera |
|---|---|---|
| Sin respuesta (corte de conexión, timeout) | Sí | Exponencial con jitter (0,5 s, 1 s, 2 s… hasta 8 s) |
| `409 IDEMPOTENCY_KEY_IN_USE` | Sí | Lo que pide `Retry-After` |
| `429 RATE_LIMITED` | Sí | `Retry-After` |
| `408` y los `5xx` (`500`, `502`, `503`, `504`…) | Sí | `Retry-After` o exponencial |
| `503 API_UNAVAILABLE` (mantenimiento) | No | — |
| Cualquier otro `4xx` | No: no se hizo nada | — |

- Por defecto, **2 reintentos** (3 intentos en total). Cambialo con `new RayaClient(config: ['max_network_retries' => …])` o por llamada con `['max_network_retries' => 0]` en las opciones.
- Si el servidor pide esperar más de 60 s (`Retry-After`), el SDK no se queda esperando: tira la excepción con `$e->getRetryAfter()` (en segundos) para que lo reprogrames.
- **Timeout:** 65 s por intento (10 s para conectar). Crear un pago puede tardar hasta 40 s si damos de alta un CVU nuevo y el servidor corta a los 60 s, así que no lo bajes de 60. Cambialo con `new RayaClient(config: ['timeout' => …])` o por llamada con `['timeout' => 90]`.
- **Tu servidor web también corta.** nginx corta a los 60 s (`fastcgi_read_timeout`), igual que el idle timeout de un ALB de AWS; también pueden cortar el `request_terminate_timeout` de PHP-FPM y Cloudflare (a los 100 s). Con 65 s por intento más los reintentos, una request web puede morir antes que el SDK: subí esos límites a 70 s o más o, mejor, creá pagos y transferencias desde una cola (un job de Laravel, Symfony Messenger) con la `idempotency_key` guardada. `ignore_user_abort(true)` deja terminar el script aunque quien navega cierre la pestaña. En Windows, `max_execution_time` también cuenta la espera de la red.

```php
use RayaCash\RayaClient;

$client = new RayaClient(config: ['max_network_retries' => 4, 'timeout' => 90]);
$balance = $client->balance->retrieve(['timeout' => 10, 'max_network_retries' => 0]);
```

## Paginación

`transfers->list()` devuelve una página (`ListObject`), de la más nueva a la más vieja. `foreach ($page as …)` recorre esa página; `autoPagingIter()` recorre todas, pidiendo las siguientes con los mismos filtros y opciones:

```php
$argentina = new DateTimeZone('America/Argentina/Buenos_Aires');
$page = $client->transfers->list([
    'direction' => 'inbound', // o 'outbound'
    'status' => 'completed', // pending, completed, failed o reversed
    // Un DateTimeInterface o un string ISO 8601 con zona. Semiabierto: los meses no se pisan.
    'created_gte' => new DateTimeImmutable('2026-10-01', $argentina),
    'created_lt' => new DateTimeImmutable('2026-11-01', $argentina),
    'limit' => 100, // de 1 a 100 (por defecto, 20)
]);
foreach ($page->autoPagingIter() as $transfer) {
    echo $transfer->id, ' ', $transfer->amount, "\n";
}

// A mano: $page->data, $page->has_more, $page->next_cursor y $page->nextPage().
```

`autoPagingIter()` es un generador: pide cada página recién cuando la necesita (cortar el `foreach` no pide más) y sus claves siguen 0, 1, 2… entre páginas, así `iterator_to_array()` no pisa nada.

Los cobros de tus pagos no aparecen en Transferencias: se siguen en cada `Payment` y con los webhooks `payment.*`.

## Errores

Todas las excepciones del SDK extienden `RayaCash\Exception\RayaException` (que extiende `\Exception`). **Decidí con `$e->getErrorCode()`** (estable), nunca con `$e->getMessage()` (texto en castellano para personas, puede cambiar). Si llega un código que el SDK no conoce, la clase sale del código HTTP.

| Clase | HTTP | Códigos | Qué hacer |
|---|---|---|---|
| `InvalidRequestException` | 400, 405, 413 y los demás `4xx` | `PARAMETER_MISSING`, `PARAMETER_INVALID`, `PARAMETER_UNKNOWN`, `INVALID_JSON`… | Corregí la request: `$e->getParam()` dice qué parámetro falló. |
| `NotFoundException` (subclase de la anterior) | 404 | `PAYMENT_NOT_FOUND`, `TRANSFER_NOT_FOUND`, `NOT_FOUND` | No existe o no es de tu cuenta. |
| `AuthenticationException` | 401 | `API_KEY_MISSING`, `API_KEY_INVALID`, `API_KEY_EXPIRED`… | Revisá la clave secreta. |
| `PermissionDeniedException` | 403 | `API_KEY_ACCOUNT_LOCKED`, `API_NOT_ENABLED`, `MERCHANT_NOT_CONFIGURED`, `TRANSFERS_BLOCKED` | La cuenta no puede hacer esto todavía. |
| `IdempotencyException` (subclase de la siguiente: atrapala antes) | 409 | `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_KEY_IN_USE` | Una clave por operación; si está en uso, reintentá más tarde con la misma. |
| `ConflictException` | 409 | `PAYMENT_NOT_REFUNDABLE` | Mirá `$e->getData()['status']` y `$e->getData()['reason'] ?? null`. |
| `UnprocessableEntityException` | 422 | `INSUFFICIENT_BALANCE`, `LIMIT_EXCEEDED`, `DESTINATION_NOT_FOUND`, `DESTINATION_INACTIVE`, `DESTINATION_NOT_SUPPORTED`, `REFUND_FAILED` | No se arregla reintentando: mostráselo a la persona. |
| `RateLimitException` | 429 | `RATE_LIMITED` | Esperá `$e->getRetryAfter()` segundos. |
| `ApiException` | 5xx y 408 | `INTERNAL_ERROR`, `BANK_UNAVAILABLE`, `CVU_UNAVAILABLE`, `API_UNAVAILABLE`, `TEMPORARILY_UNAVAILABLE` | Reintentá más tarde, en los POST con la misma `idempotency_key`. |
| `ApiConnectionException` | — | `NETWORK_ERROR`, `TIMEOUT` | No llegó respuesta: reintentá con la misma `idempotency_key`. |

Cada excepción trae `getErrorCode()`, `getMessage()`, `getData()`, `getHttpStatus()`, `getRequestId()`, `getIdempotencyKey()`, `getRetryAfter()`, `getHttpHeaders()` y el body crudo (`getHttpBody()`, `getJsonBody()`); `getCode()` es el status HTTP (0 si no hubo respuesta). Los errores de programación (un parámetro con el tipo equivocado, una opción que no existe, argumentos de más) son `\TypeError`, `\ValueError` o `\ArgumentCountError` y saltan antes de mandar nada: como no extienden `\Exception`, un `catch (\Exception $e)` alrededor de un pago no los esconde.

```php
use RayaCash\Exception\ApiException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\UnprocessableEntityException;

try {
    $transfer = $client->transfers->create(
        ['amount' => 25000, 'destination' => 'proveedor.ejemplo'],
        ['idempotency_key' => $key],
    );
} catch (UnprocessableEntityException $e) {
    if ($e->getErrorCode() === 'INSUFFICIENT_BALANCE') {
        echo 'No te alcanza el saldo.';
    } else {
        echo $e->getMessage();
    }
} catch (ApiException $e) {
    if ($e->getErrorCode() === 'BANK_UNAVAILABLE') {
        // no se descontó nada: reintentá más tarde con la misma key
    }
} catch (RayaException $e) {
    echo "Falló ({$e->getErrorCode()}). Request: {$e->getRequestId()}";
}
```

Un reembolso que la red todavía no confirmó sale como `ApiException` con `getErrorCode() === 'BANK_UNAVAILABLE'`: el pago sigue `succeeded` y la devolución nunca se manda dos veces. Esperá el webhook `payment.refunded` o reintentá más tarde con la misma clave.

## Webhooks

Te avisamos en tu servidor apenas pasa algo: `payment.succeeded` cuando llega la transferencia de un pago, `transfer.received` cuando te depositan, etc. **Antes de procesar un evento, verificá la firma**:

```php
use RayaCash\Event;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Payment;
use RayaCash\Transfer;
use RayaCash\Webhook;

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'), // el body crudo, sin parsear
        $_SERVER['HTTP_RAYA_SIGNATURE'] ?? null, // el header Raya-Signature
        getenv('RAYA_WEBHOOK_SECRET') ?: null, // whsec_…
    );
} catch (SignatureVerificationException) {
    http_response_code(400);
    exit('Firma inválida');
}

if (alreadyProcessed($event->id)) { // el mismo evento puede llegar más de una vez
    exit;
}

$object = $event->data->object ?? null; // el Payment o el Transfer, tal como estaba
if ($event->type === Event::TYPE_PAYMENT_SUCCEEDED && $object instanceof Payment) {
    fulfillOrder($object->external_reference);
} elseif ($event->type === Event::TYPE_TRANSFER_RECEIVED && $object instanceof Transfer) {
    recordDeposit($object);
}
http_response_code(200);
```

- **Usá el body crudo, tal como llegó.** Si tu framework ya parseó el JSON, volver a serializarlo cambia el body y la firma no coincide. En PHP plano, `file_get_contents('php://input')`; en Laravel y Symfony, `$request->getContent()`; con PSR-7 (Slim, Mezzio…), `$request->getBody()` tal cual, con el header de `$request->getHeaderLine('Raya-Signature')`; en WordPress, `$request->get_body()`. Hay ejemplos completos en [`examples/`](examples).
- **En Laravel, la ruta va sin CSRF** (la llama Raya, no un navegador: la firma reemplaza al token). En Laravel 11 o más nuevo, con `$middleware->validateCsrfTokens(except: ['webhooks/raya'])` en `bootstrap/app.php`; en Laravel 10, en el `$except` de `VerifyCsrfToken`.
- Respondé `2xx` en menos de 15 segundos. Si tenés que hacer algo lento, guardá el evento, respondé y procesalo después.
- El mismo evento puede llegar más de una vez y desordenado: guardá los `$event->id` que procesaste y, si necesitás el estado actual, consultá el objeto con la API.
- `$event->data->object` es el `Transfer` o el `Payment` tal como estaba cuando ocurrió el evento: distinguilos con `instanceof`. Leelo con `?? null`: un tipo de evento nuevo puede no traer `data`.
- «Enviar evento de prueba» (en Developers › Webhooks) manda un `webhook.test`: respondé `2xx` e ignoralo.
- La firma tolera 5 minutos de diferencia con tu reloj (`$tolerance = 300`, en segundos; `null` o `0` la desactivan). Mientras rotás el secreto llegan dos firmas: alcanza con que coincida una.
- Con `$client->constructEvent(…)` el evento queda ligado a ese cliente: `$event->data->object->refund()` usa su clave.

## Tests de tu integración

Para probar tu código sin llamar a la API, usá un `http_client` falso: no sale nada a la red.

```php
use RayaCash\HttpClient\HttpClientInterface;
use RayaCash\HttpClient\Request;
use RayaCash\HttpClient\Response;
use RayaCash\RayaClient;

$fakeHttpClient = new class implements HttpClientInterface {
    public function send(Request $request): Response
    {
        assert(parse_url($request->url, PHP_URL_PATH) === '/v1/balance');

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"object":"balance","available":1500,"currency":"ARS"}',
        );
    }
};

$client = new RayaClient('raya_sk_live_test', ['http_client' => $fakeHttpClient]);
assert($client->balance->retrieve()->available === 1500);
```

`assert()` corre con `zend.assertions = 1`, como en desarrollo; con PHPUnit, usá `self::assertSame()`. Con el estilo global, pasalo con `RayaCash::setHttpClient($fakeHttpClient)` y llamá a `RayaCash::reset()` entre tests. Para simular un corte, tirá `TransportException::network('…')` o `TransportException::timeout()` desde `send()`: el SDK la convierte en `ApiConnectionException` y reintenta, así que en tus tests usá `'max_network_retries' => 0` para no esperar.

Para probar tu endpoint de webhooks, firmá el body con `WebhookSignature::generateHeader($body, $secret)` y mandalo en el header `Raya-Signature`. Para armar objetos sin hacer requests: `Payment::constructFrom(['object' => 'payment', 'id' => '…', 'status' => 'succeeded'])`.

## Configuración avanzada

```php
use RayaCash\HttpClient\CurlClient;
use RayaCash\RayaClient;

$client = new RayaClient([
    'api_key' => 'raya_sk_live_…',
    'api_base' => 'https://api.raya.cash', // https:// (o http://localhost para desarrollo)
    'timeout' => 65, // segundos por intento
    'max_network_retries' => 2,
    // El tuyo, por ejemplo con un proxy o un certificado propio: no lo cerramos.
    'http_client' => new CurlClient([
        CURLOPT_PROXY => 'http://proxy.interno:3128',
        CURLOPT_CAINFO => '/etc/ssl/certs/mi-ca.pem',
    ]),
    'app_info' => ['name' => 'mi-plugin', 'version' => '1.2.0', 'url' => 'https://plugin.example'],
]);
```

- **Proxies y certificados:** pasá un `CurlClient` con opciones de curl (`CURLOPT_PROXY`, `CURLOPT_CAINFO`…). Las que maneja el SDK (URL, método, headers, body, timeouts y redirects) no se aceptan, y apagar la verificación TLS o `CURLOPT_VERBOSE` (escribe el header `Authorization`, con la clave entera) tampoco: para ver las requests, usá `RAYA_LOG=debug`, y para un problema de conexión, `curl -v https://api.raya.cash` en la terminal. Tus opciones pueden llevar la contraseña del proxy: no aparecen en `var_dump()` ni (desde PHP 8.2) en las trazas, y un `CurlClient` no se serializa. libcurl también respeta `https_proxy`. En Windows, si ves `curl 60` o `curl 77`, falta el certificado de CA: configurá `curl.cainfo` en tu `php.ini` o pasá `CURLOPT_CAINFO`. Para usar otra librería HTTP, implementá `HttpClientInterface`.
- **`app_info`:** identifica tu plugin en el `User-Agent` (`rayacash-php/1.0.0 … mi-plugin/1.2.0`), que ves en [Developers › Logs](https://raya.cash/dashboard/developers/logs). En el estilo global: `RayaCash::setAppInfo('mi-plugin', version: '1.2.0')`.
- **Logging:** con `RAYA_LOG=info` (o `RayaCash::setLog('info')`) ves cada request, su status, su `request_id` y los reintentos en STDERR (en la consola) o en el `error_log()` de PHP (en FPM o Apache); con `debug`, también la query y la idempotency key. Con `RayaCash::setLogger($logger)`, un logger PSR-3 (Monolog, el de Laravel, el de Symfony) recibe cada línea, aunque no haya `RAYA_LOG`. Nunca logueamos la clave ni los bodies.
- **Cerrar:** `$client->close()` suelta la conexión, para workers largos (Octane, un consumidor de colas). Se puede llamar más de una vez y el cliente sigue usable: la próxima request abre otra. El `http_client` que pasaste no se toca.
- **Endpoints nuevos:** si la API suma un endpoint antes que el SDK, usá `$client->rawRequest('get', '/v1/…', params: […])` o `$client->rawRequest('post', '/v1/…', body: […])`: tiene la misma autenticación, reintentos y errores.
- **Estilo global:** `RayaCash::setApiBase()`, `setTimeout()`, `setMaxNetworkRetries()`, `setHttpClient()`, `setAppInfo()`, `setLog()` y `setLogger()` (cada uno con su `get…()`, salvo la clave), y `RayaCash::reset()`, que vuelve todo a los valores de fábrica.

## Seguridad

- **La clave secreta va solo en tu servidor**, leída de una variable de entorno o de la configuración de tu framework. Nunca en el navegador, en una app mobile ni en el repositorio. Para cobrar desde el navegador está [Raya.js](https://raya.cash/raya-js), con la clave pública.
- El SDK rechaza la clave pública (`raya_pk_…`) sin mandar nada, nunca la muestra completa (`var_dump($client)` y `print_r($client)` la enmascaran, y no aparece en `var_export()` ni en `json_encode()`) y nunca sigue redirects. Un `RayaClient` no se deja serializar.
- Cada parámetro del SDK que puede llevar la clave está marcado con `#[\SensitiveParameter]`: desde PHP 8.2, no aparece en las trazas de los errores. En PHP 8.1, dejá `zend.exception_ignore_args = On` (el valor de `php.ini-production`), así las trazas no muestran argumentos.
- Si la clave se filtró, rotala en Developers › API Keys con vencimiento «Ahora»: la anterior deja de funcionar al instante.
- Las requests que hacés con la clave secreta quedan 30 días en [Developers › Logs](https://raya.cash/dashboard/developers/logs).

## Versionado y compatibilidad

- [Semver](https://semver.org/lang/es/): los cambios incompatibles solo llegan en una versión mayor (`2.0.0`). Las menores (`1.1.0`) suman cosas sin romper nada y las de parche (`1.0.1`) corrigen errores. Todo queda anotado en el [CHANGELOG](CHANGELOG.md).
- La API puede sumar campos, valores de enums, eventos, códigos de error y endpoints. El SDK los deja pasar: tu código tiene que ignorar lo que no conoce.
- PHP 8.1 a 8.5. Cada objeto declara sus campos (`@property-read`) y cada método, la forma de sus arrays: tu IDE y PHPStan conocen los campos (los estados son uniones de literales, como `'pending'|'succeeded'|…`, y pueden sumarse valores) y PHPStan avisa si `transfers->create` va sin `idempotency_key`.

## Desarrollo

```bash
scripts/dev.sh test        # tests en PHP 8.4, con Docker y sin red (nunca llaman a la API)
scripts/dev.sh matrix      # los tests en PHP 8.1, 8.2, 8.3, 8.4 y 8.5
scripts/dev.sh lint        # formato PER-CS (php-cs-fixer) y 100 columnas
scripts/dev.sh analyse     # PHPStan al nivel máximo y los tipos de la API pública
scripts/dev.sh coverage    # cobertura mínima del 95 % (líneas, ramas y funciones)
scripts/dev.sh package     # composer validate, la versión y el paquete instalado en limpio
scripts/dev.sh all         # todo lo anterior
```

Solo necesitás Docker: cada versión de PHP tiene su imagen y su `vendor/` (en un volumen), y los tests corren con `--network none`. `scripts/dev.sh help` lista el resto de los comandos. Con PHP y Composer instalados, también andan `composer test`, `composer lint` y `composer analyse`. Los ejemplos de [`examples/`](examples) cargan `vendor/autoload.php`: desde este repo, corré `composer install` antes.

Para publicar una versión: actualizá `RayaCash::VERSION` (en `src/RayaCash.php`), el CHANGELOG y la línea de la versión de este README (`composer check:version` comprueba que digan lo mismo), y creá un release `vX.Y.Z` en GitHub sobre el commit con el CI en verde. Packagist toma el tag solo, sin tokens, y el workflow `release.yml` corre los tests y verifica que el tag sea la versión.

---

Referencia de la API: https://raya.cash/api · Soporte: soporte@raya.cash

Raya Cash S.A
