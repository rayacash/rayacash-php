<?php

declare(strict_types=1);

// Reembolsar un pago: devuelve todo lo recibido a la cuenta desde la que se pagó.
//
// ATENCIÓN: correr este script devuelve la plata del pago.
//
//   RAYA_SECRET_KEY=raya_sk_live_… php examples/reembolsar-un-pago.php <payment_id>

use RayaCash\Exception\ApiException;
use RayaCash\Exception\ConflictException;
use RayaCash\RayaClient;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$paymentId = $argv[1] ?? null;
if ($paymentId === null) {
    fwrite(STDERR, "Uso: php examples/reembolsar-un-pago.php <payment_id>\n");
    exit(1);
}
$idempotencyKey = Uuid::v4(); // en tu sistema, guardala con el reembolso antes de llamar
$client = new RayaClient();

try {
    $payment = $client->payments->refund($paymentId, ['idempotency_key' => $idempotencyKey]);
    echo "Pago {$payment->id}: {$payment->status} ({$payment->refunded_at})\n";
} catch (ConflictException $e) {
    // PAYMENT_NOT_REFUNDABLE: data['status'] es el estado actual del pago y, si está succeeded,
    // data['reason'] dice por qué (refund_window_expired, payer_unknown…).
    $data = $e->getData() ?? [];
    $status = is_string($data['status'] ?? null) ? $data['status'] : '';
    $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
    echo "No se puede reembolsar: {$status} {$reason}\n";
} catch (ApiException $e) {
    if ($e->getErrorCode() !== 'BANK_UNAVAILABLE') {
        throw $e;
    }
    // Se está confirmando: nunca se manda dos veces. Esperá payment.refunded.
    echo "La devolución se está confirmando: reintentá más tarde con la clave {$idempotencyKey}.\n";
}
