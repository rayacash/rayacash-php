<?php

declare(strict_types=1);

// Cobrar con un pago: crea un pago y muestra los datos para transferir.
//
// ATENCIÓN: correr este script crea un pago en tu negocio, que reserva un CVU hasta que vence
// (24 h, salvo que tu negocio tenga otro plazo).
//
//   RAYA_SECRET_KEY=raya_sk_live_… php examples/cobrar-con-un-pago.php

use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new RayaClient(); // lee RAYA_SECRET_KEY

// En tu sistema: generá la clave y guardala con el pedido ANTES de llamar, para que un reintento
// (incluso después de un corte de tu proceso) no cree otro pago.
$idempotencyKey = Uuid::v4();

$payment = $client->payments->create(
    [
        'amount' => 15000,
        'description' => 'Pedido #1042',
        'external_reference' => 'pedido-1042',
        'return_url' => 'https://tienda.example.com/pedidos/1042',
        'metadata' => ['cliente_id' => '8812'],
    ],
    ['idempotency_key' => $idempotencyKey],
);

echo "Pago {$payment->id}: {$payment->status}\n";
echo 'Transferí $', $payment->amount, " al CVU {$payment->bank_transfer->cvu}\n";
if ($payment->bank_transfer->alias !== null) {
    echo "(alias {$payment->bank_transfer->alias})\n";
}
echo "O pagá en {$payment->checkout_url}, antes de {$payment->expires_at}\n";

// Entregá el pedido cuando llegue el webhook payment.succeeded. Si quien paga vuelve a return_url,
// consultá el pago: volver no significa que haya pagado.
$payment = $client->payments->retrieve($payment->id);
if ($payment->status === Payment::STATUS_SUCCEEDED) {
    echo "¡Pagado! Ya podés entregar el pedido.\n";
}
