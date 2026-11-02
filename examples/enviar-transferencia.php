<?php

declare(strict_types=1);

// Enviar una transferencia a un CBU, CVU o alias, sin riesgo de mandarla dos veces.
//
// ATENCIÓN: correr este script transfiere plata desde tu saldo.
//
//   RAYA_SECRET_KEY=raya_sk_live_… php examples/enviar-transferencia.php <destino> <monto>

use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\RayaClient;
use RayaCash\Transfer;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

function pay_supplier(RayaClient $client, string $destination, string $amount, string $key): void
{
    try {
        // El monto viaja con el mismo valor que escribiste ("25000.50" sale como 25000.5): el
        // SDK nunca redondea.
        $transfer = $client->transfers->create(
            ['amount' => $amount, 'destination' => $destination],
            ['idempotency_key' => $key],
        );
    } catch (UnprocessableEntityException $e) {
        // INSUFFICIENT_BALANCE, LIMIT_EXCEEDED, DESTINATION_*: no se arregla reintentando.
        echo "No se pudo transferir: {$e->getMessage()} ({$e->getErrorCode()})\n";
        return;
    } catch (ApiConnectionException|ApiException $e) {
        // Sin respuesta o falla de nuestro lado: reintentá más tarde con la MISMA clave.
        echo "Reintentá más tarde con la clave {$e->getIdempotencyKey()}: {$e->getMessage()}\n";
        return;
    }

    echo "Transferencia {$transfer->id}: {$transfer->status}\n";
    echo "Titular: {$transfer->counterparty->name} (CUIT {$transfer->counterparty->tax_id})\n";
    if ($transfer->status === Transfer::STATUS_PENDING) {
        echo 'Te avisamos con transfer.sent o transfer.failed (en general, en uno o dos minutos).'
            . "\n";
    }
}

$destination = $argv[1] ?? null;
$amount = $argv[2] ?? null;
if ($destination === null || $amount === null) {
    fwrite(STDERR, "Uso: php examples/enviar-transferencia.php <destino> <monto>\n");
    exit(1);
}
// En tu sistema: generá la clave y guardala con el pago al proveedor ANTES de llamar. Si la
// transferencia termina failed, para intentarla de nuevo usá una clave nueva.
pay_supplier(new RayaClient(), $destination, $amount, Uuid::v4());
