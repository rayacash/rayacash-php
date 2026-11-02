<?php

declare(strict_types=1);

// Webhooks con Laravel: $request->getContent() da el body crudo para verificar la firma.
//
// Copialo a app/Http/Controllers/RayaWebhookController.php y sumá la ruta en routes/web.php:
//
//   Route::post('/webhooks/raya', RayaWebhookController::class);
//
// La ruta va sin CSRF: la llama Raya, no un navegador, y la firma reemplaza al token. En
// Laravel 11 o más nuevo, en bootstrap/app.php:
//
//   ->withMiddleware(function (Middleware $middleware): void {
//       $middleware->validateCsrfTokens(except: ['webhooks/raya']);
//   })
//
// En Laravel 10, sumala al $except de app/Http/Middleware/VerifyCsrfToken.php. El secreto va en
// config/services.php ('raya' => ['webhook_secret' => env('RAYA_WEBHOOK_SECRET')]) y el
// RayaClient sale del singleton de tu AppServiceProvider (ver «Laravel, Symfony y WordPress» en
// el README).

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RayaCash\Event;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\Transfer;

final class RayaWebhookController
{
    public function __invoke(Request $request, RayaClient $client): Response
    {
        try {
            // Ligado a $client: $event->data->object->refund() usaría su clave.
            $event = $client->constructEvent(
                $request->getContent(), // el body crudo: $request->all() lo re-serializaría
                $request->header('Raya-Signature'),
                config('services.raya.webhook_secret'),
            );
        } catch (SignatureVerificationException) {
            return response('Firma inválida', 400);
        }

        // Guardá $event->id en tu base (con un índice único) para procesar cada evento una sola
        // vez y, si el proceso es lento, despachá un job y respondé enseguida: hay 15 s.
        $object = $event->data->object ?? null; // ?? porque no todo evento trae data
        if ($event->type === Event::TYPE_PAYMENT_SUCCEEDED && $object instanceof Payment) {
            // Los eventos pueden llegar desordenados: si necesitás el estado actual, consultalo.
            $payment = $client->payments->retrieve($object->id);
            if ($payment->status === Payment::STATUS_SUCCEEDED) {
                Log::info("Entregá el pedido {$payment->external_reference}");
            }
        } elseif ($event->type === Event::TYPE_TRANSFER_RECEIVED && $object instanceof Transfer) {
            Log::info("Depósito de \${$object->net_amount}");
        }
        // webhook.test y los tipos que no conocés: respondé 2xx e ignoralos.
        return response('', 200);
    }
}
