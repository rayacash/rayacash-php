# rayacash-php

El SDK oficial de PHP para la [API de Raya Cash](https://raya.cash/api). Te deja cobrar por transferencia con un CVU exclusivo por pago, mandar plata a cualquier CBU, CVU o alias, consultar tu saldo, conciliar tus depósitos y verificar los webhooks, con reintentos seguros e idempotencia incluidos. Y si tu aplicación cobra a nombre de otras cuentas de Raya (un marketplace, una plataforma), conectarlas y crear pagos a su nombre con tu comisión, y enviarles pesos a tus vendedores desde tu saldo con payouts ([Raya Connect](#raya-connect)). También te deja verificar la identidad de tus clientes con su DNI, una prueba de vida y el Registro Nacional de las Personas ([Identidad](#identidad)).

```php
use RayaCash\RayaClient;

$client = new RayaClient(); // lee tu clave secreta de RAYA_SECRET_KEY

$payment = $client->payments->create(['amount' => 15000, 'description' => 'Pedido #1042']);
echo $payment->bank_transfer->cvu, ' ', $payment->checkout_url, "\n";
```

Versión 1.2.0. Requiere PHP 8.1 o más nuevo, con las extensiones curl y json. Los cambios de cada versión están en [CHANGELOG.md](CHANGELOG.md).

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
- [Raya Connect](#raya-connect)
- [Identidad](#identidad)
- [Sandbox](#sandbox)
- [Tests de tu integración](#tests-de-tu-integración)
- [Configuración avanzada](#configuración-avanzada)
- [Seguridad](#seguridad)
- [Versionado y compatibilidad](#versionado-y-compatibilidad)
- [Desarrollo](#desarrollo)

## Antes de empezar

1. **Tu cuenta de Raya con la identidad verificada** (si no, `API_KEY_ACCOUNT_LOCKED`) y la API habilitada (si no, `API_NOT_ENABLED`: escribinos a soporte@raya.cash).
2. **Tu clave secreta** (`raya_sk_live_…`), de [Developers › API Keys](https://raya.cash/dashboard/developers/api-keys). La vas a ver completa una sola vez: guardala en una variable de entorno de tu servidor. Si tu cuenta tiene [sandbox](#sandbox), cada sandbox tiene además su clave secreta de prueba (`raya_sk_test_…`), para probar sin plata real.
3. **Para cobrar:** tu negocio configurado en [Configuración › Negocio](https://raya.cash/dashboard/settings/business). Sin eso, crear un pago da `MERCHANT_NOT_CONFIGURED`.
4. **Para los webhooks:** un destino en [Developers › Webhooks](https://raya.cash/dashboard/developers/webhooks) y su secreto (`whsec_…`).
5. **Para cobrar a nombre de otras cuentas:** una aplicación en [Developers › Integraciones › Apps](https://raya.cash/dashboard/developers/apps), con su `client_id`, su `client_secret` (`raya_cs_live_…`, que ves una sola vez) y tus URLs de redirección. Ver [Raya Connect](#raya-connect).
6. **Para enviar payouts a tus vendedores:** el descriptor del comercio configurado en [Configuración › Negocio](https://raya.cash/dashboard/settings/business). Sin eso, crear un payout da `PAYOUT_ORIGIN_UNAVAILABLE`. Ver [Payouts](#payouts).
7. **Para verificar la identidad de tus clientes:** Identidad activada en tu cuenta (la pedís desde [Identidad](https://raya.cash/dashboard/identity), en el dashboard: nos contás para qué la vas a usar y revisamos tu solicitud; hasta que la aprobemos, crear una verificación da `IDENTITY_NOT_ACTIVATED`) y, para el resultado, un destino de webhooks con los eventos de «Identidad». Identidad todavía no está disponible en el [sandbox](#sandbox): con una clave de prueba (`raya_sk_test_…`) responde 403 `SANDBOX_NOT_SUPPORTED`. Ver [Identidad](#identidad).

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

Cada respuesta es un objeto del SDK (`Balance`, `Transfer`, `Payment`, `Event`, `ListObject`; en Raya Connect, `OAuthToken`, `User`, `ConnectOrder` y `ConnectPayout`, y en Identidad, `IdentityVerification` e `IdentityVerificationFiles`): `$payment->status` y `$payment['status']` dan lo mismo, `json_encode($payment)` da el JSON exacto de la API (con el `serialize_precision` de fábrica, `-1`) y `$payment->toArray()`, arrays comunes. Los valores vienen tal como los manda la API: montos `int` o `float` y fechas en string ISO 8601 (`'2026-10-24T14:00:00Z'`). Si la API suma un campo nuevo, lo ves igual (`$payment['campo_nuevo']`). Leer un campo que no existe tira `\OutOfBoundsException`; para uno que puede faltar, `$payment->campo ?? null` e `isset($payment->campo)` nunca fallan. Los estados y tipos tienen constantes (`Payment::STATUS_SUCCEEDED`, `Transfer::DIRECTION_INBOUND`, `Event::TYPE_PAYMENT_SUCCEEDED`…), y pueden sumarse valores: tu código tiene que ignorar los que no conoce.

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

También están `OAuth::token()`, `User::retrieve()`, `ConnectOrder::create()`, `ConnectOrder::retrieve()` y `ConnectOrder::refundById()` (ver [Raya Connect](#raya-connect)): con la clave global puesta en el `access_token` de la cuenta conectada. Los payouts (`ConnectPayout::create()`, `ConnectPayout::retrieve()` y `ConnectPayout::list()`) van con tu clave secreta, como las transferencias, y las verificaciones de identidad también: `IdentityVerification::create()`, `retrieve()`, `list()`, `cancelById()` y `files()`, y `$verification->cancel()` (ver [Identidad](#identidad)). En un [sandbox](#sandbox), con su clave secreta de prueba como clave global, también `Payment::simulateTransfer()`. Como la configuración global dura todo el proceso, para operar con varias cuentas conectadas usá un `RayaClient` por cuenta.

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

- **`transfers->create` y `connect->payouts->create` exigen la `idempotency_key`.** Generá un UUID v4 (`RayaCash\Util\Uuid::v4()`) para cada transferencia o payout, **guardalo con la operación en tu base antes de llamar** y reusalo en cada reintento. Así, si tu proceso se corta después de mandar la request, el reintento no envía la plata dos veces.
- En `payments->create` y `payments->refund` es opcional pero recomendada, como en `identity->verifications->create` y `cancel`. Si no la pasás, el SDK genera una que protege solo los reintentos de esa llamada.
- `oauth->token` y el test helper del [sandbox](#sandbox) (`testHelpers->payments->simulateTransfer()`) son los únicos POST sin `Idempotency-Key`, y sus opciones no aceptan `idempotency_key`: el `code` se canjea una sola vez y un pago se simula una sola vez.
- El SDK reintenta siempre con la misma clave. La que usó queda en `$obj->getLastResponse()->idempotencyKey` y en `$e->getIdempotencyKey()`, para que puedas reintentar más tarde.
- Guardamos cada clave 24 horas, para toda tu cuenta. Usar la misma clave con otros parámetros da `IdempotencyException` con `getErrorCode() === 'IDEMPOTENCY_KEY_REUSED'`.
- Si una transferencia o un payout terminó `failed`, para intentarlo de nuevo usá una clave **nueva**: con la misma te devolvemos la respuesta original.

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
| `InvalidRequestException` | 400, 405, 413 y los demás `4xx` | `PARAMETER_MISSING`, `PARAMETER_INVALID`, `PARAMETER_UNKNOWN`, `INVALID_JSON`, `OAUTH_GRANT_INVALID`, `OAUTH_GRANT_TYPE_UNSUPPORTED`… | Corregí la request: `$e->getParam()` dice qué parámetro falló. Con `OAUTH_GRANT_INVALID`, el code ya se usó o venció: la cuenta tiene que volver a autorizar. |
| `NotFoundException` (subclase de la anterior) | 404 | `PAYMENT_NOT_FOUND`, `TRANSFER_NOT_FOUND`, `USER_NOT_FOUND`, `ORDER_NOT_FOUND`, `PAYOUT_NOT_FOUND`, `VERIFICATION_NOT_FOUND`, `NOT_FOUND` | No existe o no es de tu cuenta (o de la cuenta conectada). |
| `AuthenticationException` | 401 | `API_KEY_MISSING`, `API_KEY_INVALID`, `API_KEY_EXPIRED`, `ACCESS_TOKEN_MISSING`, `ACCESS_TOKEN_INVALID`, `ACCESS_TOKEN_REVOKED`, `OAUTH_CLIENT_INVALID`… | Revisá la clave secreta (o el access_token: con `ACCESS_TOKEN_REVOKED`, la cuenta desconectó tu app). |
| `PermissionDeniedException` | 403 | `API_KEY_ACCOUNT_LOCKED`, `API_NOT_ENABLED`, `MERCHANT_NOT_CONFIGURED`, `TRANSFERS_BLOCKED`, `CONNECTED_ACCOUNT_LOCKED`, `CONNECTED_ACCOUNT_NOT_CONFIGURED`, `APP_DISABLED`, `PAYOUT_ORIGIN_UNAVAILABLE`, `SANDBOX_NOT_SUPPORTED`, `SANDBOX_ONLY`, `IDENTITY_NOT_ACTIVATED` | La cuenta (o la cuenta conectada, o tu app) no puede hacer esto todavía. `SANDBOX_NOT_SUPPORTED`: ese endpoint todavía no está disponible en el sandbox (usaste una credencial de prueba), como los de Identidad. `SANDBOX_ONLY`: `testHelpers` solo funciona en un [sandbox](#sandbox), con su clave secreta de prueba. `IDENTITY_NOT_ACTIVATED`: activá Identidad desde el dashboard antes de crear verificaciones. Si no la aprobamos, escribinos a soporte@raya.cash. |
| `IdempotencyException` (subclase de la siguiente: atrapala antes) | 409 | `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_KEY_IN_USE` | Una clave por operación; si está en uso, reintentá más tarde con la misma. |
| `ConflictException` | 409 | `PAYMENT_NOT_REFUNDABLE`, `PAYMENT_NOT_PENDING`, `VERIFICATION_NOT_CANCELABLE` | Mirá `$e->getData()['status']` (el estado del pago o de la verificación) y, en un reembolso, `$e->getData()['reason'] ?? null`. `PAYMENT_NOT_PENDING`: en un sandbox, ese pago ya no se puede simular. |
| `UnprocessableEntityException` | 422 | `INSUFFICIENT_BALANCE`, `LIMIT_EXCEEDED`, `DESTINATION_NOT_FOUND`, `DESTINATION_INACTIVE`, `DESTINATION_NOT_SUPPORTED`, `REFUND_FAILED` | No se arregla reintentando: mostráselo a la persona. |
| `RateLimitException` | 429 | `RATE_LIMITED` | Esperá `$e->getRetryAfter()` segundos. En Identidad, si llegaste a un tope de tu cuenta, `$e->getData()['limit']` es `open` o `daily`. |
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
- `$event->data->object` es el `Transfer`, el `Payment`, el `ConnectOrder` o la `IdentityVerification` tal como estaba cuando ocurrió el evento: distinguilos con `instanceof`. Leelo con `?? null`: un tipo de evento nuevo puede no traer `data`.
- Si tu aplicación cobra a nombre de otras cuentas, recibís `connect.order.created`, `connect.order.succeeded`, `connect.order.failed` y `connect.order.refunded` (`Event::TYPE_CONNECT_ORDER_*`), con el `ConnectOrder`; la cuenta conectada recibe sus `payment.*`. Ver [Raya Connect](#raya-connect).
- Si verificás la identidad de tus clientes, recibís `identity.verification.verified`, `identity.verification.declined`, `identity.verification.expired` e `identity.verification.canceled` (`Event::TYPE_IDENTITY_VERIFICATION_*`), con la `IdentityVerification`, siempre con `person` en `null`. Ver [Identidad](#identidad).
- «Enviar evento de prueba» (en Developers › Webhooks) manda un `webhook.test`: respondé `2xx` e ignoralo.
- `$event->livemode` es `true` en tu cuenta real y `false` en un [sandbox](#sandbox). Un evento creado antes de que la API sumara el campo no lo trae si se reenvía: leelo con `$event->livemode ?? true`.
- La firma tolera 5 minutos de diferencia con tu reloj (`$tolerance = 300`, en segundos; `null` o `0` la desactivan). Mientras rotás el secreto llegan dos firmas: alcanza con que coincida una.
- Con `$client->constructEvent(…)` el evento queda ligado a ese cliente: `$event->data->object->refund()` usa su clave.

## Raya Connect

Con [Raya Connect](https://raya.cash/api/connect), una **aplicación** de Raya (de [Developers › Integraciones › Apps](https://raya.cash/dashboard/developers/apps)) cobra a nombre de otras cuentas de Raya que la conectan, con una comisión fija por cobro que se liquida a tu cuenta. Es el equivalente de las aplicaciones y el OAuth de Mercado Pago, con `fee` en lugar de `marketplace_fee`. Además, con [payouts](#payouts) les enviás pesos a tus vendedores desde tu saldo, con tu clave secreta. El flujo para cobrar a nombre de una cuenta:

1. **El link de autorización**, en tu web (por ejemplo, en un botón «Conectar con Raya»): `https://raya.cash/authorization?client_id=<client_id>&response_type=code&redirect_uri=<tu callback>`. La `redirect_uri` tiene que estar registrada en tu app, idéntica. Lo recomendado es sumarle un `state` nuevo en cada conexión (ver abajo).
2. **La cuenta autoriza** (ve el nombre y la imagen de tu app y los permisos: cobrar a su nombre y ver sus datos) y Raya la manda a tu `redirect_uri` con `?code=…` (y el `state`, si lo mandaste). El `code` dura 10 minutos y se canjea una sola vez.
3. **El canje:** `$client->oauth->token(…)` devuelve el `access_token` de la cuenta (`raya_at_live_…`) y su `user_id`. Guardalos juntos en tu servidor, con la cuenta de tu plataforma (la de tu login, nunca la del `state`): el token no vence.
4. **Por la cuenta conectada:** un `RayaClient` con su `access_token` como clave lee sus datos (`users`) y crea pagos a su nombre (`connect->orders`). Al acreditarse, lo recibido menos `fee` se liquida al comercio conectado y `fee` a tu cuenta (los dos a los días de liquidación de cada uno, adelantables); un reembolso revierte las dos partes.

El `state` es opcional, pero recomendado: evita que alguien le haga conectar a tu usuario una cuenta que no es la suya (CSRF). No es un valor fijo ni se escribe a mano, y no sirve para pasar datos: viaja en la URL y cualquiera lo puede cambiar. Tu botón apunta a una ruta de tu servidor que, en cada clic, genera uno aleatorio nuevo, lo guarda en la sesión y redirige al link con `&state=<ese valor>`:

```php
// /raya/conectar: tu botón «Conectar con Raya» apunta acá.
session_start();
$state = bin2hex(random_bytes(32)); // aleatorio y nuevo en cada clic
$_SESSION['raya_state'] = $state;
header('Location: https://raya.cash/authorization?' . http_build_query([
    'client_id' => '7650337728110670',
    'response_type' => 'code',
    'redirect_uri' => 'https://tienda.example.com/raya/callback',
    'state' => $state,
]));
exit;
```

En el callback, antes de canjear el `code`, comparás el `state` que volvió con el guardado:

```php
use RayaCash\RayaClient;

// Tu callback (la redirect_uri): llegan ?code=…&state=…
session_start();
$client = new RayaClient(); // para este paso no hace falta ninguna clave

// El state se usa una sola vez: sacalo de la sesión y comparalo con el que volvió.
$expected = $_SESSION['raya_state'] ?? null;
unset($_SESSION['raya_state']);
if ($expected === null || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
    http_response_code(400);
    exit('state inválido'); // no salió de tu botón: no canjees el code
}
if (($_GET['error'] ?? null) === 'access_denied') {
    header('Location: /conectar?cancelado=1'); // la cuenta no autorizó
    exit;
}
$token = $client->oauth->token([
    'client_id' => '7650337728110670',
    'client_secret' => getenv('RAYA_CLIENT_SECRET') ?: '', // raya_cs_live_…, solo en tu servidor
    'code' => $_GET['code'],
    'redirect_uri' => 'https://tienda.example.com/raya/callback', // idéntica a la del link
]);
// grant_type lo pone el SDK. Guardá access_token y user_id juntos: el token no vence.
$account->saveRayaConnection($token->access_token, $token->user_id);
```

```php
use RayaCash\ConnectOrder;
use RayaCash\RayaClient;

// Un cliente por cuenta conectada, con su access_token como clave.
$connected = new RayaClient($account->raya_access_token);

// Los datos de la cuenta: solo con su propio token (cualquier otro id da USER_NOT_FOUND). Nunca
// viene la foto del documento ni el número de trámite.
$user = $connected->users->retrieve($account->raya_user_id); // o 'me'
echo $user->full_name, ' ', $user->identification?->number, "\n";

// Un pago a su nombre, con tu comisión fija (fee, en pesos: un monto, no un porcentaje).
$connectOrder = $connected->connect->orders->create(
    [
        'items' => [['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000]],
        'fee' => 500,
        'external_reference' => 'pedido-1042',
        'back_urls' => [
            'success' => 'https://tienda.example.com/pedidos/1042',
            'failure' => 'https://tienda.example.com/pedidos/1042/error',
        ],
        'auto_return' => 'approved', // al acreditarse, quien paga vuelve solo a success
        'notification_url' => 'we_3fN8kQ2vX7mB1pR5tZ9cW4yD', // tu destino de Developers › Webhooks
        'metadata' => ['pedido_id' => '1042'],
    ],
    ['idempotency_key' => $order->connect_idempotency_key], // un UUID v4 guardado con el pedido
);
// Mandá a quien paga a la página del pago, con el nombre y la imagen del comercio conectado:
echo $connectOrder->checkout_url, "\n";
echo $connectOrder->net_amount, "\n"; // lo que se liquida a la cuenta conectada: 44500

// Después: consultarlo, o reembolsarlo (total: la cuenta devuelve lo recibido y tu comisión se
// revierte). Entregá solo cuando status es succeeded.
$connectOrder = $connected->connect->orders->retrieve($connectOrder->id);
if ($connectOrder->status === ConnectOrder::STATUS_SUCCEEDED) {
    $connectOrder = $connectOrder->refund(['idempotency_key' => $refund->idempotency_key]);
}
```

- **`oauth->token` es la única request sin `Authorization`:** la app se identifica con `client_id` y `client_secret`, así que anda con un cliente sin clave, y sus opciones no aceptan `api_key` ni `idempotency_key` (no lleva `Idempotency-Key`: el code se canjea una vez). `OAUTH_CLIENT_INVALID` es un `client_id` o un `client_secret` equivocado (o la cuenta dueña de la app no puede operar: bloqueada, inactiva o sin la identidad verificada); `OAUTH_GRANT_INVALID`, un code usado, vencido o una `redirect_uri` distinta.
- **El `access_token` es una clave más:** va en `Authorization: Bearer`, se valida igual y el SDK lo enmascara igual (`raya_at_live_…9hJk`), también en `(string) $token`, `var_dump` y `print_r` del `OAuthToken` (`$token->access_token`, `toArray()` y `json_encode()` lo dan entero, para guardarlo). `expires_in` y `refresh_token` vienen en `null`: no vence y no hay refresh. Deja de valer cuando la cuenta desconecta tu app desde Configuración › Apps externas (`ACCESS_TOKEN_REVOKED`: pedile que vuelva a conectarla) o cuando la vuelve a autorizar (recibís uno nuevo). Con él solo podés usar `users` y `connect->orders`; el resto de la API, incluidos los payouts, va con tu clave secreta.
- **`create` recibe** `items` (de 1 a 20, cada uno con `title`, `quantity` y `unit_price`; el total es lo que transfiere quien paga), `fee` (de 0 a menos que el total; por defecto 0), `description`, `external_reference`, `metadata`, `back_urls` (`success` y `failure`), `auto_return` (`'approved'`), `notification_url` (el id `we_…` o la URL de un destino tuyo de Developers › Webhooks; sin él, los destinos suscriptos a `connect.order.*`) y `expires_in` (de 300 a 864000 segundos; por defecto, el plazo del comercio conectado). `fee` y `unit_price` aceptan lo mismo que `amount` ([Montos](#montos)). La cuenta no necesita haber configurado su negocio en Raya: si no lo hizo (`$user->merchant` viene en `null`), el primer pago le crea el comercio con sus datos legales; `CONNECTED_ACCOUNT_NOT_CONFIGURED` queda para el caso raro de una cuenta sin CUIT/CUIL válido (lo resuelve soporte).
- **El `ConnectOrder`** trae `status` (`pending`, `succeeded`, `failed`, `expired` o `refunded`), `amount`, `fee`, `net_amount`, `amount_received`, `items`, `account` (el `user_id` de la cuenta conectada), `bank_transfer` (el CVU y el alias exclusivos, del comercio conectado), `checkout_url`, `back_urls`, `auto_return`, `notification_url`, `payer`, `failure_code`, `metadata`, `expires_at`, `paid_at`, `refunded_at` y `created_at`. Su `id` es el de su pago (`Payment`): la cuenta conectada lo ve con su propia clave en `payments->retrieve()`.
- **Los webhooks** `connect.order.created`, `connect.order.succeeded`, `connect.order.failed` y `connect.order.refunded` te llegan a vos (al destino de `notification_url`, o a los suscriptos), con el `ConnectOrder` en `$event->data->object`; la cuenta conectada recibe sus `payment.*`. Los límites de requests se cuentan sobre tu cuenta, y estas requests no aparecen en Developers › Logs.
- **Estilo global:** `OAuth::token([…])`, y con `RayaCash::setApiKey($accessToken)`, `User::retrieve($userId)`, `ConnectOrder::create([…])`, `ConnectOrder::retrieve($id)`, `ConnectOrder::refundById($id)` y `$connectOrder->refund()`. Hay un ejemplo completo en [`examples/cobrar-a-nombre-de-una-cuenta.php`](examples/cobrar-a-nombre-de-una-cuenta.php).

### Payouts

Un payout le envía pesos desde tu saldo a un vendedor de tu plataforma, por CBU, CVU o alias: cobrás a tus compradores con `$client->payments->create()` y después le transferís a cada vendedor lo que le toca. Descontamos la comisión del monto y te devolvemos el desglose. Se autentica con **tu clave secreta**, no con el `access_token` de una cuenta conectada: el dinero sale de tu saldo.

```php
use RayaCash\ConnectPayout;
use RayaCash\RayaClient;

$client = new RayaClient(); // lee tu clave secreta de RAYA_SECRET_KEY

// La idempotency_key es obligatoria: un UUID v4 que guardás con la operación ANTES de llamar y
// reusás en cada reintento. Así nunca se envía dos veces.
$connectPayout = $client->connect->payouts->create(
    ['amount' => 60000, 'destination' => 'ana.ejemplo'], // un CBU, un CVU o un alias
    ['idempotency_key' => $payout->idempotency_key],
);
echo $connectPayout->status, "\n"; // pending, o completed si el destino es una cuenta de Raya
echo $connectPayout->processing_fee, ' ', $connectPayout->net_amount, "\n"; // 870.25 59129.75
echo $connectPayout->counterparty->name, "\n"; // el titular de la cuenta de destino
echo $connectPayout->origin->name, ' ', $connectPayout->origin->account, "\n"; // desde dónde sale

// Consultarlo después.
$connectPayout = $client->connect->payouts->retrieve($connectPayout->id);
if ($connectPayout->status === ConnectPayout::STATUS_COMPLETED) {
    echo $connectPayout->reference, "\n"; // el identificador en la red de transferencias
}

// Tus payouts, del más nuevo al más viejo.
$page = $client->connect->payouts->list(['status' => 'completed', 'limit' => 100]);
foreach ($page->autoPagingIter() as $connectPayout) {
    echo $connectPayout->id, ' ', $connectPayout->net_amount, "\n";
}
```

- **`create` recibe** `amount` (lo que sale de tu saldo; la comisión se descuenta de acá y la cuenta de destino recibe `net_amount`, o sea `amount` menos `processing_fee`) y `destination` (un CBU o CVU de 22 dígitos o un alias, siempre como string). Buscamos al titular y te lo devolvemos en `counterparty`: no hay paso de confirmación, así que revisá el destino antes de llamar. Si reintentás con la misma `idempotency_key`, no se envía dos veces; si un payout terminó `failed`, para volver a intentarlo usá una clave nueva.
- **El `ConnectPayout`** trae `status` (`pending`, `completed`, `failed` o `reversed`), `amount`, `processing_fee`, `net_amount`, `currency`, `origin` (la cuenta de tu negocio desde la que sale: `account`, `alias` y `name`), `counterparty` (el titular de la cuenta de destino: `name`, `tax_id`, `account` y `alias`), `reference` (el identificador en la red de transferencias: `null` mientras está `pending`, si falló o cuando el destino es una cuenta de Raya) y `created_at`. Si falla o se revierte, te devolvemos `amount` entero, incluida la comisión, y `processing_fee` se mantiene.
- **Te avisamos por webhook, con `transfer.sent` o `transfer.failed`:** no hay eventos propios de payouts. El `id` del payout es también el de la transferencia que lo representa en `$client->transfers->retrieve()` y en los webhooks `transfer.*`, con su monto en `amount`, su comisión en `fee` y lo que recibe el vendedor en `net_amount`: si conciliás transferencias y payouts, no los cuentes dos veces. Un payout a una cuenta de Raya se completa al instante.
- **Tu saldo:** `amount` sale de tu saldo disponible (`$client->balance->retrieve()`), y los cobros se suman a tu saldo cuando se liquidan: un pago `succeeded` no significa que ya puedas enviar ese dinero (`INSUFFICIENT_BALANCE`).
- **`PAYOUT_ORIGIN_UNAVAILABLE`** (403) significa que tu negocio todavía no tiene una cuenta propia desde la que enviar payouts: configurá el descriptor del comercio en [Configuración › Negocio](https://raya.cash/dashboard/settings/business). Un payout que no existe o no es de tu cuenta da `PAYOUT_NOT_FOUND` (404).
- **Estilo global:** con `RayaCash::setApiKey($secretKey)` (tu clave secreta, nunca un `access_token`), `ConnectPayout::create([…], ['idempotency_key' => …])`, `ConnectPayout::retrieve($id)` y `ConnectPayout::list([…])`.
- Los payouts todavía no están disponibles en el sandbox: con una clave de prueba (`raya_sk_test_…`) responden 403 `SANDBOX_NOT_SUPPORTED`.

**Probar en un sandbox.** Si tu cuenta tiene [sandbox](#sandbox), ahí recorrés el flujo entero sin una segunda cuenta de Raya y sin plata real: creás la app y usuarios de prueba, los conectás con tu link de autorización, `$client->oauth->token(…)` te da un `access_token` de prueba (`raya_at_test_…`) y con él cobrás a su nombre; después simulás la transferencia y te llegan los webhooks (ver [Raya Connect con usuarios de prueba](#raya-connect-con-usuarios-de-prueba)).

`$client->oauth` y `$client->users` existen desde la 1.1.0, y `$client->connect`, `ConnectOrder` y `ConnectPayout`, desde la 1.2.0. Si venís de la 1.1.0: `$client->checkout->orders`, `Checkout` y `Event::TYPE_CHECKOUT_*` siguen andando como alias deprecados hasta la 2.0.0; en código nuevo usá los nombres de arriba.

## Identidad

Con [Identidad](https://raya.cash/api/identity) verificás la identidad de tus clientes: la persona le saca una foto a su DNI y hace una prueba de vida, y validamos sus datos y su cara contra el Registro Nacional de las Personas (RENAPER). La completa en nuestra página, desde su teléfono o su computadora: vos no manejás fotos ni datos biométricos. Todo va con tu clave secreta, desde tu servidor. El flujo:

1. **Creás la verificación** con `$client->identity->verifications->create()` y obtenés su `url`.
2. **La persona la completa:** le mandás la `url` (por mail, por WhatsApp, en un botón) o la abrís adentro de tu web con [Identity.js](#embeber-en-tu-web-con-identityjs).
3. **Te avisamos el resultado** con los webhooks `identity.verification.*`.
4. **Leés el resultado** con `retrieve()`: si está `verified`, trae los datos de la persona en `person`. Dala por buena solo cuando `status` es `verified`.

```php
use RayaCash\IdentityVerification;
use RayaCash\RayaClient;

$client = new RayaClient(); // lee RAYA_SECRET_KEY

// 1. Creá la verificación y mandale el link a la persona.
$verification = $client->identity->verifications->create(
    [
        'type' => 'document',
        'external_reference' => 'cliente-8812', // tu id del cliente, sin datos personales
        'return_url' => 'https://tienda.example.com/cuenta',
        'expires_in' => 86400, // un día, en segundos (por defecto, 7 días)
        'metadata' => ['plan' => 'pro'],
    ],
    ['idempotency_key' => $customer->verification_idempotency_key], // un UUID v4 guardado antes
);
echo $verification->url, "\n";

// 2. Cuando termina (mejor, cuando llega el webhook), leé el resultado.
$verification = $client->identity->verifications->retrieve($verification->id);
if ($verification->status === IdentityVerification::STATUS_VERIFIED) {
    $customer->saveIdentity($verification->person); // solo acá vienen sus datos
} elseif ($verification->status === IdentityVerification::STATUS_DECLINED) {
    echo $verification->decline_reason?->code, "\n"; // decidí con code, no con message
}
```

```php
// 3. Las imágenes, si te hacen falta: URLs firmadas que vencen a los 5 minutos.
$files = $client->identity->verifications->files($verification->id);
if ($files->selfie !== null) {
    $customer->saveSelfie(file_get_contents($files->selfie->url)); // bajala enseguida
}

// 4. Si ya no hace falta, cancelala (solo pending o in_progress).
$verification = $client->identity->verifications->cancel($verification->id);

// 5. Y todas las tuyas, de la más nueva a la más vieja.
$page = $client->identity->verifications->list(['status' => 'verified', 'limit' => 100]);
foreach ($page->autoPagingIter() as $verification) {
    echo $verification->id, ' ', $verification->external_reference, "\n";
}
```

| Método | Request | Devuelve |
|---|---|---|
| `$client->identity->verifications->create([…], ['idempotency_key' => …])` | `POST /v1/identity/verifications` | `IdentityVerification`, `pending` |
| `$client->identity->verifications->retrieve($verificationId)` | `GET /v1/identity/verifications/:id` | `IdentityVerification`, con `person` si está `verified` |
| `$client->identity->verifications->list([…])` | `GET /v1/identity/verifications` | `ListObject` de `IdentityVerification` |
| `$client->identity->verifications->cancel($verificationId, ['idempotency_key' => …])` | `POST /v1/identity/verifications/:id/cancel` | `IdentityVerification`, `canceled` |
| `$client->identity->verifications->files($verificationId)` | `GET /v1/identity/verifications/:id/files` | `IdentityVerificationFiles` |

- **Dos tipos:** `document` es la completa: DNI (frente y dorso), prueba de vida y validación contra el registro. `biometric` es solo una prueba de vida, comparada con la de una `document` anterior de la misma persona: sirve para volver a confirmar a alguien que ya verificaste (por ejemplo, antes de una operación sensible). Lleva `reference`, el `id` de una verificación `document` de tu cuenta ya `verified`, con `checks->registry->face_match` en `hit`:

```php
$verification = $client->identity->verifications->create([
    'type' => IdentityVerification::TYPE_BIOMETRIC,
    'reference' => 'idv_3kT9vQ2mXw7Lp0aBcD4eF6gH',
]);
```

- **`create` recibe** `type` (obligatorio), `external_reference` (tu identificador, hasta 128 caracteres en una línea: no pongas datos personales, que al filtrar la lista viaja en la URL), `metadata` (hasta 20 pares de texto), `return_url` (la URL `https://` a la que vuelve la persona desde la pantalla final), `expires_in` (de 3600 a 2592000 segundos; por defecto, 7 días) y `reference` (solo con `biometric`). La `idempotency_key` es opcional pero recomendada: guardala con tu registro antes de llamar y reusala si reintentás. Un parámetro que no es de la API falla antes de mandar nada; los rangos los valida la API (`PARAMETER_INVALID`, con `$e->getParam()`).
- **Los estados** (`IdentityVerification::STATUS_*`): `pending` (la persona todavía no empezó), `in_progress` (tocó «Empezar»), `processing` (terminó la captura y estamos validando contra el registro), `verified`, `declined` (mirá `decline_reason->code`), `expired` (no la terminó antes de `expires_at`) y `canceled`. Los cuatro últimos no cambian más: para volver a intentar, creá otra.
- **El resultado:** `checks` trae cada control (`document`, `liveness`, `face_match` y `registry`) con su `status` (`passed`, `failed`, `pending`, `unavailable` o `not_applicable`); `decline_reason`, el motivo de un rechazo (`code`: `face_mismatch`, `registry_not_found`, `registry_data_mismatch`, `registry_unavailable` o `attempts_exhausted`, en `IdentityVerification::DECLINE_CODE_*`); `person`, los datos de la persona según el registro (nombre, documento, CUIL, domicilio y el ejemplar del DNI), solo cuando está `verified` y **nunca en los eventos**; y `amount`, lo que se cobra por esa verificación, fijo desde que pasa a `verified` (`null` en cualquier otro estado). Se cobra solo cada verificación que termina `verified`.
- **Cancelar** solo se puede con una `pending` o `in_progress`: si no, `ConflictException` con `getErrorCode() === 'VERIFICATION_NOT_CANCELABLE'` y el estado en `$e->getData()['status']`. Repetirlo sobre una ya cancelada la devuelve igual. Desde una verificación que ya tenés: `$verification->cancel()`.
- **Las imágenes** (`files()`): `front` y `back` (el DNI) y `selfie`, cada una con una `url` firmada que vence a los 5 minutos (`expires_at`). Bajalas enseguida con tu propio cliente HTTP y no guardes las URLs; las que esa verificación no tiene vienen en `null`.
- **La lista** acepta `status`, `type`, `external_reference` (coincidencia exacta), `created_gte` y `created_lt` (un `DateTimeInterface` o un string ISO 8601 con zona) y `limit` (de 1 a 100), y se recorre con `autoPagingIter()`, como la de transferencias ([Paginación](#paginación)).
- **Topes:** cada cuenta tiene un tope de verificaciones abiertas y otro de creaciones por día. Al llegar, `RateLimitException` con `$e->getData()['limit']` (`open` o `daily`) y `getRetryAfter()` en `3600.0` (una hora): el SDK no se queda esperando.
- **Estilo global:** con `RayaCash::setApiKey($secretKey)`, `IdentityVerification::create([…])`, `retrieve($id)`, `list([…])`, `cancelById($id)` (PHP no deja tener un `cancel()` estático y otro de instancia) y `files($id)`. Hay un ejemplo en [`examples/verificar-una-identidad.php`](examples/verificar-una-identidad.php).

**Los webhooks** te avisan cuando una verificación termina. Verificá la firma como en [Webhooks](#webhooks): `$event->data->object` es la `IdentityVerification`, siempre con `person` en `null`.

| Evento | Cuándo |
|---|---|
| `identity.verification.verified` (`Event::TYPE_IDENTITY_VERIFICATION_VERIFIED`) | Pasó todos los controles: pedí `person` con `retrieve()`. |
| `identity.verification.declined` (`Event::TYPE_IDENTITY_VERIFICATION_DECLINED`) | No pudimos verificarla: mirá `decline_reason->code`. |
| `identity.verification.expired` (`Event::TYPE_IDENTITY_VERIFICATION_EXPIRED`) | Pasó `expires_at` sin que la persona la terminara. |
| `identity.verification.canceled` (`Event::TYPE_IDENTITY_VERIFICATION_CANCELED`) | La cancelaste. |

```php
use RayaCash\Event;
use RayaCash\IdentityVerification;

// $event, de Webhook::constructEvent(…), ya con la firma verificada.
$verification = $event->data->object ?? null; // siempre con person en null
if ($verification instanceof IdentityVerification) {
    if ($event->type === Event::TYPE_IDENTITY_VERIFICATION_VERIFIED) {
        // Los eventos nunca traen los datos de la persona: pedilos con la API.
        $verification = $client->identity->verifications->retrieve($verification->id);
        $customer->saveIdentity($verification->person);
    } elseif ($event->type === Event::TYPE_IDENTITY_VERIFICATION_DECLINED) {
        echo $verification->decline_reason?->code, "\n";
    }
}
```

### Embeber en tu web con Identity.js

La persona puede hacer la verificación sin salir de tu sitio. Tu servidor la crea con este SDK y tu clave secreta; tu página la abre con **Identity.js**, que no usa ninguna clave: al navegador solo llega el `id`. Identity.js se carga de dos formas, con el mismo código: **`npm install rayacash-identity`**, si tu frontend usa un bundler, o un `<script>` desde el CDN de Raya.

**1.** En tu servidor, creá la verificación y devolvele el `id` a tu página:

```php
// Tu ruta POST /verificaciones: la llama tu página cuando la persona toca «Verificarme».
$verification = $client->identity->verifications->create([
    'type' => 'document',
    'external_reference' => (string) $customer->id, // el cliente de tu sesión
]);
header('Content-Type: application/json');
echo json_encode(['id' => $verification->id]);
```

**2.** En tu página, abrila con Identity.js cuando la persona toca tu botón (`<button id="verificar" type="button">Verificarme</button>`). Con npm:

```js
import { RayaIdentity } from "rayacash-identity";

const identity = RayaIdentity();
document.querySelector("#verificar").addEventListener("click", async () => {
  const response = await fetch("/verificaciones", { method: "POST" });
  const { id } = await response.json();
  const result = await identity.open(id);
  if (result.status === "completed") {
    // la persona terminó: mostrale que estás revisando y esperá el webhook
  }
});
```

**3.** Confirmá en tu servidor. Lo que ves en el navegador es solo para la pantalla: esperá el webhook `identity.verification.verified` o consultá `retrieve($id)` antes de dar por verificada a la persona. `closed` no quiere decir que no se verificó: pudo haber seguido en otra pestaña.

- **Sin bundler,** con un `<script>` desde el CDN de Raya: `<script src="https://cdn.raya.cash/identity/v1/identity.js"></script>` deja `RayaIdentity` en `window`. Después va el mismo código, sin el `import`, en un `<script>` tuyo y después del botón. `v1` es siempre la última 1.x; para fijar una versión, `https://cdn.raya.cash/identity/v1.0.0/identity.js` con el `integrity` que publica el CHANGELOG de `rayacash-identity` y `crossorigin="anonymous"`. Nunca lo cargues desde un CDN de terceros.
- **Si tu sitio manda una Content-Security-Policy,** sumá `frame-src https://raya.cash` (y, si manda `Permissions-Policy`, `camera=(self "https://raya.cash")`). Con el `<script>`, sumá también `https://cdn.raya.cash` a `script-src`, y el código de tu página va en un archivo tuyo (lo cubre `'self'`) o con tu nonce: un `<script>` inline sin nonce no corre. Con npm, `script-src` no cambia.
- Tu página tiene que ser `https://` (`http://localhost` sirve para desarrollar). Si el navegador no deja usar la cámara dentro de tu página, Identity.js le ofrece a la persona seguir en una pestaña nueva. Sin Identity.js también funciona: mandá a la persona a `$verification->url` y usá `return_url` para que vuelva.

La referencia completa de Identidad, con Identity.js, está en https://raya.cash/api/identity.

## Sandbox

Un sandbox es una cuenta aparte de tu cuenta real, solo para probar: cobrás con la misma API y el mismo SDK, simulás la transferencia de quien paga y te llegan los webhooks, sin mover plata. Si en el menú de tu perfil del dashboard aparece «Crear sandbox» o «Sandboxes», tu cuenta lo tiene: creá uno desde ahí (hasta 5 por cuenta). Si no aparece, tu cuenta todavía no lo tiene.

- **Las claves de prueba:** cada sandbox nace con su clave secreta de prueba (`raya_sk_test_…`) y su clave pública de prueba (`raya_pk_test_…`), en Developers › API Keys del sandbox. El cliente acepta tu clave secreta (`raya_sk_live_…`) o el `access_token` de una cuenta conectada (`raya_at_live_…`), y sus pares de prueba (`raya_sk_test_…` y `raya_at_test_…`), con la misma `api_base`: la clave decide el entorno. Tu código es el mismo; cambia la clave.
- **Los pagos de prueba:** `payments->create()` devuelve un pago con un CVU de prueba (empieza con `00000002` y no es de ninguna cuenta), el alias `sandbox.no.transferir` y una `checkout_url` en `https://raya.cash/sandbox/pay/<id>`, la página de pago con el botón «Simular transferencia» (por el monto exacto; la puede usar cualquiera que tenga el link). Nunca le transfieras plata a esos datos. Vence a las 24 horas.

```php
use RayaCash\Payment;
use RayaCash\RayaClient;

$client = new RayaClient(); // con RAYA_SECRET_KEY en la clave secreta de prueba (raya_sk_test_…)

$payment = $client->payments->create(['amount' => 2500, 'description' => 'Pedido 1043']);
echo $payment->bank_transfer->alias, "\n"; // sandbox.no.transferir: no le transfieras nada

// Lo que en tu cuenta real hace quien paga: la transferencia, simulada por el monto exacto.
$payment = $client->testHelpers->payments->simulateTransfer($payment->id);
if ($payment->status === Payment::STATUS_SUCCEEDED) {
    // como en tu cuenta real: te llega el webhook payment.succeeded, con livemode en false
}

// Con otro monto: menos que el pago lo deja failed (amount_mismatch); igual o más, succeeded.
$payment = $client->payments->create(['amount' => 2500]);
$payment = $client->testHelpers->payments->simulateTransfer($payment->id, ['amount' => 1000]);
echo $payment->status, ' ', $payment->failure_code, "\n"; // failed amount_mismatch

$balance = $client->balance->retrieve(); // tu saldo de prueba: sube al simular
```

| Método | Request | Devuelve |
|---|---|---|
| `$client->testHelpers->payments->simulateTransfer($paymentId, ['amount' => …])` | `POST /v1/test/payments/:id/simulate_transfer` | El `Payment`, o el `ConnectOrder` si el id es el de un pago de Raya Connect |
| `Payment::simulateTransfer($paymentId, ['amount' => …])` (estilo global) | `POST /v1/test/payments/:id/simulate_transfer` | Lo mismo |

- **`simulateTransfer()`** recibe el id de un pago de prueba `pending` y, si querés, `amount`: lo que se simula recibir, en pesos, como en [Montos](#montos). Sin `amount`, el monto exacto: el pago queda `succeeded`. Con menos, `failed`, con `failure_code` en `amount_mismatch` (en un sandbox no se devuelve solo: en tu cuenta real, sí); con más, `succeeded`, con lo simulado en `amount_received`. Devuelve el objeto según su `object`: distinguí un `Payment` de un `ConnectOrder` con `instanceof`. También podés simular (y reembolsar) desde «Pagos» en el dashboard del sandbox.
- **No lleva `Idempotency-Key`** y sus opciones no aceptan `idempotency_key`: un pago se simula una sola vez. Uno que ya no está `pending` (simulado, reembolsado o vencido) da `ConflictException` con `getErrorCode() === 'PAYMENT_NOT_PENDING'` y su estado en `$e->getData()['status']`: si te pasa al reintentar después de un corte de red, el pago ya se simuló (consultalo con `payments->retrieve()`; una orden de Raya Connect, con `$connected->connect->orders->retrieve()` y el `access_token` de prueba). Un id que no es de tu sandbox da `NotFoundException` con `PAYMENT_NOT_FOUND`.
- **Solo en un sandbox:** con tu clave real, `testHelpers` da `PermissionDeniedException` con `getErrorCode() === 'SANDBOX_ONLY'`. No lo dejes en el código de producción.
- **El saldo y los reembolsos:** `$client->balance->retrieve()` da tu saldo de prueba, que sube al simular, en el acto, por lo recibido. `payments->refund()` devuelve todo al instante, sin pasar por el banco ni mirar el saldo, hasta 30 días después de `paid_at`, y te llega `payment.refunded`.
- **Los webhooks:** cada sandbox tiene sus propios destinos (hasta 3, en Developers › Webhooks del sandbox), con 3 intentos por entrega, y solo emite `payment.*`, `connect.order.*` y el `webhook.test` de «Enviar evento de prueba». Sus eventos traen `$event->livemode` en `false`; los de tu cuenta real, en `true`. La firma se verifica igual, con el secreto (`whsec_…`) de ese destino. Creá el destino antes de simular: cada destino recibe solo los eventos que ocurren después de crearlo, así que los de un pago que simulaste antes no le llegan ni se pueden reenviar. Para probar en tu máquina sin publicar una URL, usá rayacash-cli (necesita Node.js 22 o más nuevo): copiá a `RAYA_WEBHOOK_SECRET` el secreto de tu máquina que muestra `npx rayacash-cli listen --print-secret` y abrí la escucha con `npx rayacash-cli listen --forward-to localhost:8000/webhooks/raya` (toma la clave secreta de prueba de la variable `RAYA_SECRET_KEY` de esa terminal, no de tu `.env`, o de `npx rayacash-cli login`): le reenvía a tu servidor los eventos del sandbox que ocurren con la escucha abierta, firmados con ese secreto.
- **Los límites:** el sandbox tiene sus propios límites de requests y un tope diario de pagos de prueba. Pasados, la API responde 429 `RATE_LIMITED` con `Retry-After`, como en tu cuenta real.
- **Lo que todavía no está disponible en el sandbox:** las transferencias (`transfers`), los payouts (`connect->payouts`) e Identidad: con una clave de prueba responden 403 `SANDBOX_NOT_SUPPORTED`. Raya.js todavía no acepta la clave pública de prueba.
- **Al pasar a tu cuenta real:** cambiá la clave por la real (`raya_sk_live_…`, que creás con verificación facial o desde tu teléfono), sacá `testHelpers`, configurá tu negocio en Configuración › Negocio y volvé a crear tus destinos de webhooks (en `RAYA_WEBHOOK_SECRET`, el secreto del destino de tu cuenta real) y tus apps: lo del sandbox no pasa a tu cuenta real. Ahí cada pago `pending` tiene su propio CVU y su alias, un pago de menos se devuelve solo (queda `refunded`), lo cobrado se suma a tu saldo cuando se liquida y un reembolso puede fallar por saldo (`INSUFFICIENT_BALANCE`).

### Raya Connect con usuarios de prueba

En el sandbox recorrés el flujo de [Raya Connect](#raya-connect) entero, sin una segunda cuenta de Raya:

1. **La app:** creala en Developers › Apps del sandbox. Su `client_secret` es de prueba (`raya_cs_test_…`) y lo ves una sola vez, como el real.
2. **Los usuarios de prueba:** crealos en «Usuarios de prueba», en el dashboard del sandbox (hasta 20). Cada uno tiene un email `…@sandbox.raya.cash`, una contraseña (la ves y la copiás desde ahí) y los datos de una persona ficticia.
3. **La conexión:** abrí tu link de autorización e ingresá con el email y la contraseña del usuario de prueba. Al canjear el `code` con el `client_secret` de prueba, `$client->oauth->token(…)` te da su `access_token` de prueba (`raya_at_test_…`) y su `user_id`.
4. **Los pagos a su nombre:** con ese token, `users->retrieve('me')` y `connect->orders` andan como con uno real. Para simular la transferencia de una orden, usá la clave secreta de prueba del sandbox (la de la cuenta dueña de la app; con el `access_token` no se puede) y el `id` de la orden: devuelve el `ConnectOrder`. Para consultarla o reembolsarla, volvé al cliente con el `access_token` (`$connected->connect->orders`). Lo recibido menos `fee` va al saldo de prueba del usuario de prueba y `fee`, al de tu sandbox.

```php
use RayaCash\ConnectOrder;
use RayaCash\RayaClient;

// El access_token de prueba del usuario de prueba (raya_at_test_…), de oauth->token.
$connected = new RayaClient($token->access_token);
$connectOrder = $connected->connect->orders->create([
    'items' => [['title' => 'Pedido 7', 'quantity' => 1, 'unit_price' => 1500]],
    'fee' => 10,
]);

// La transferencia, con la clave secreta de prueba del sandbox (la cuenta dueña de la app).
$client = new RayaClient(); // RAYA_SECRET_KEY: raya_sk_test_…
$connectOrder = $client->testHelpers->payments->simulateTransfer($connectOrder->id);
if ($connectOrder instanceof ConnectOrder) {
    echo $connectOrder->status, ' ', $connectOrder->net_amount, "\n"; // succeeded 1490
}
```

Las apps de un sandbox solo se conectan con los usuarios de prueba de ese sandbox: una cuenta real no se puede conectar a una app de un sandbox, y un usuario de prueba no se conecta a una app real. Rotar la contraseña de un usuario de prueba o desconectarlo desde el dashboard todavía no se puede.

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

El `http_client` falso prueba tu código sin red; el flujo real (cobrar, simular la transferencia y recibir los webhooks y, con una app, autorizar y canjear el `code`) se prueba en un [sandbox](#sandbox), si tu cuenta lo tiene.

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
- **`app_info`:** identifica tu plugin en el `User-Agent` (`rayacash-php/1.2.0 … mi-plugin/1.2.0`), que ves en [Developers › Logs](https://raya.cash/dashboard/developers/logs). En el estilo global: `RayaCash::setAppInfo('mi-plugin', version: '1.2.0')`.
- **Logging:** con `RAYA_LOG=info` (o `RayaCash::setLog('info')`) ves cada request, su status, su `request_id` y los reintentos en STDERR (en la consola) o en el `error_log()` de PHP (en FPM o Apache); con `debug`, también la query y la idempotency key. Con `RayaCash::setLogger($logger)`, un logger PSR-3 (Monolog, el de Laravel, el de Symfony) recibe cada línea, aunque no haya `RAYA_LOG`. Nunca logueamos la clave ni los bodies.
- **Cerrar:** `$client->close()` suelta la conexión, para workers largos (Octane, un consumidor de colas). Se puede llamar más de una vez y el cliente sigue usable: la próxima request abre otra. El `http_client` que pasaste no se toca.
- **Endpoints nuevos:** si la API suma un endpoint antes que el SDK, usá `$client->rawRequest('get', '/v1/…', params: […])` o `$client->rawRequest('post', '/v1/…', body: […])`: tiene la misma autenticación, reintentos y errores.
- **Estilo global:** `RayaCash::setApiBase()`, `setTimeout()`, `setMaxNetworkRetries()`, `setHttpClient()`, `setAppInfo()`, `setLog()` y `setLogger()` (cada uno con su `get…()`, salvo la clave), y `RayaCash::reset()`, que vuelve todo a los valores de fábrica.

## Seguridad

- **La clave secreta va solo en tu servidor**, leída de una variable de entorno o de la configuración de tu framework. Nunca en el navegador, en una app mobile ni en el repositorio. Para cobrar desde el navegador está [Raya.js](https://raya.cash/raya-js), con la clave pública.
- El SDK rechaza la clave pública (`raya_pk_…`) sin mandar nada, nunca la muestra completa (`var_dump($client)` y `print_r($client)` la enmascaran, y no aparece en `var_export()` ni en `json_encode()`) y nunca sigue redirects. Un `RayaClient` no se deja serializar.
- Cada parámetro del SDK que puede llevar la clave está marcado con `#[\SensitiveParameter]`: desde PHP 8.2, no aparece en las trazas de los errores. En PHP 8.1, dejá `zend.exception_ignore_args = On` (el valor de `php.ini-production`), así las trazas no muestran argumentos.
- Si la clave se filtró, rotala en Developers › API Keys con vencimiento «Ahora»: la anterior deja de funcionar al instante.
- **En una app:** el `client_secret` y los `access_token` de las cuentas conectadas son secretos como la clave: guardalos solo en tu servidor. Si el `client_secret` se filtró, regeneralo en Developers › Apps (el anterior deja de valer); si se filtró un `access_token`, la cuenta puede desconectar tu app desde Configuración › Apps externas o volver a autorizarla (el token anterior muere). El SDK enmascara los `access_token` como a la clave, pero el `client_secret` viaja en el body de `oauth->token`: con las trazas con argumentos (`zend.exception_ignore_args = Off`, el valor de desarrollo), la traza de un error de la API en esa llamada incluye la request con su body; con el valor de producción (`On`), no.
- **En Identidad,** `person` y las imágenes son datos personales de quien se verifica: usalos solo para lo que esa persona aceptó, guardá lo mínimo y no los escribas en logs (`print_r($verification)` los muestra). Los eventos nunca traen `person`. En el navegador va Identity.js, que no usa ninguna clave: solo el `id` de la verificación, que conviene darle solo a la persona que se tiene que verificar.
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
