<?php

declare(strict_types=1);

// Raya Connect: canjea el code de una cuenta que conectó tu aplicación por su access_token, lee
// sus datos y crea un pago a su nombre, con tu comisión.
//
// ATENCIÓN: correr este script canjea el code (una sola vez) y crea un pago en el negocio de la
// cuenta conectada, que reserva un CVU hasta que vence.
//
//   RAYA_CLIENT_ID=7650337728110670 RAYA_CLIENT_SECRET=raya_cs_live_… \
//     php examples/cobrar-a-nombre-de-una-cuenta.php <code> <redirect_uri>

use RayaCash\RayaClient;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$code = $argv[1] ?? null;
$redirectUri = $argv[2] ?? null;
if ($code === null || $redirectUri === null) {
    fwrite(STDERR, "Uso: php examples/cobrar-a-nombre-de-una-cuenta.php <code> <redirect_uri>\n");
    exit(1);
}

// 1. El canje: sin clave secreta (la app se identifica con client_id y client_secret).
$client = new RayaClient();
$token = $client->oauth->token([
    'client_id' => (string) getenv('RAYA_CLIENT_ID'),
    'client_secret' => (string) getenv('RAYA_CLIENT_SECRET'),
    'code' => $code,
    'redirect_uri' => $redirectUri, // idéntica a la del link de autorización
]);
// En tu sistema: guardá access_token y user_id juntos. El token no vence.
echo "Cuenta conectada: {$token->user_id}\n";

// 2. Por la cuenta conectada: un cliente con su access_token como clave.
$connected = new RayaClient($token->access_token);
$user = $connected->users->retrieve($token->user_id);
echo "{$user->full_name} ({$user->identification?->type} {$user->identification?->number})\n";
// No hace falta que haya configurado su negocio: si $user->merchant es null, el primer pago se lo
// crea.

// 3. Un pago a su nombre, con tu comisión fija (un monto en pesos, no un porcentaje).
$idempotencyKey = Uuid::v4(); // en tu sistema, guardala con el pedido ANTES de llamar
$connectOrder = $connected->connect->orders->create(
    [
        'items' => [['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000]],
        'fee' => 500,
        'external_reference' => 'pedido-1042',
        'back_urls' => ['success' => 'https://tienda.example.com/pedidos/1042'],
        'auto_return' => 'approved',
    ],
    ['idempotency_key' => $idempotencyKey],
);
echo "Pago {$connectOrder->id}: {$connectOrder->status}\n";
echo 'Quien paga transfiere $', $connectOrder->amount;
echo " al CVU {$connectOrder->bank_transfer->cvu}\n";
// Sin negocio configurado, $user->merchant es null: es el nombre de la cuenta.
$seller = $user->merchant->name ?? $user->full_name ?? 'la cuenta';
echo "A {$seller} le quedan \$", $connectOrder->net_amount;
echo '; tu comisión, $', $connectOrder->fee, "\n";
echo "O pagá en {$connectOrder->checkout_url}, antes de {$connectOrder->expires_at}\n";

// Te avisamos con connect.order.succeeded (y a la cuenta conectada, con payment.succeeded).
