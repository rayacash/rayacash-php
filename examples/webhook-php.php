<?php

declare(strict_types=1);

// Webhooks en PHP plano, sin framework: php://input da el body crudo para verificar la firma.
//
//   composer require rayacash/rayacash-php
//   RAYA_WEBHOOK_SECRET=whsec_… php -S localhost:8000 examples/webhook-php.php
//
// En producción, apuntá la ruta de tu destino de Developers › Webhooks a este archivo (nginx con
// PHP-FPM, Apache…). Con FPM, RAYA_WEBHOOK_SECRET va en el pool: env[RAYA_WEBHOOK_SECRET] = …

use RayaCash\Event;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Payment;
use RayaCash\Transfer;
use RayaCash\Webhook;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$secret = getenv('RAYA_WEBHOOK_SECRET');
if ($secret === false || $secret === '') {
    http_response_code(500);
    exit('Falta RAYA_WEBHOOK_SECRET (el whsec_… de Developers › Webhooks).');
}

// El body crudo, tal como llegó: pasarlo por json_decode() y json_encode() lo cambia.
$body = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_RAYA_SIGNATURE'] ?? null; // el header Raya-Signature
try {
    $event = Webhook::constructEvent($body, is_string($signature) ? $signature : null, $secret);
} catch (SignatureVerificationException) {
    http_response_code(400);
    exit('Firma inválida');
}

// Guardá $event->id en tu base (con un índice único) para procesar cada evento una sola vez: el
// mismo evento puede llegar más de una vez. Si el proceso es lento, encolalo y respondé enseguida:
// hay 15 s.
$object = $event->data->object ?? null; // ?? porque no todo evento trae data
if ($event->type === Event::TYPE_PAYMENT_SUCCEEDED && $object instanceof Payment) {
    error_log("Pagaron el pedido {$object->external_reference}: entregalo.");
} elseif ($event->type === Event::TYPE_PAYMENT_FAILED) {
    error_log('Transfirieron de menos: devolvemos el dinero solos. No entregues el pedido.');
} elseif ($event->type === Event::TYPE_TRANSFER_RECEIVED && $object instanceof Transfer) {
    error_log("Depósito de \${$object->net_amount}");
}
// webhook.test y los tipos que no conocés: respondé 2xx e ignoralos.
http_response_code(200);
