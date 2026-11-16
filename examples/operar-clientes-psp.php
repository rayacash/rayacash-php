<?php

declare(strict_types=1);

// Raya PSP: crea un cliente con un CVU a su nombre, lo muestra con su saldo y sus últimas
// transferencias, o transfiere en su nombre.
//
// ATENCIÓN: «crear» crea un cliente en tu cuenta y «transferir» mueve plata (del saldo del cliente
// y del tuyo). Raya PSP es solo para cuentas de empresa con Raya PSP activado.
//
//   export RAYA_SECRET_KEY=raya_sk_live_…
//   php examples/operar-clientes-psp.php crear <idv_…>
//   php examples/operar-clientes-psp.php ver <pspc_…>
//   php examples/operar-clientes-psp.php transferir <pspc_…> <destino> <monto>

use RayaCash\Exception\ConflictException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\PspCustomer;
use RayaCash\PspTransfer;
use RayaCash\RayaClient;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new RayaClient(); // lee RAYA_SECRET_KEY
$command = $argv[1] ?? null;
$first = $argv[2] ?? null;
$second = $argv[3] ?? null;
$third = $argv[4] ?? null;

if ($command === 'crear' && $first !== null) {
    // Una persona humana que se verificó con Raya Identity: alcanza con el id de su verificación
    // (document y verified), porque su CUIL, su nombre y su sexo salen de ahí. Sin Raya Identity,
    // con tu propio KYC, se manda tax_id, name, person_type F y las URLs de las fotos de su DNI y
    // de una selfie (document_front_url, document_back_url y selfie_url).
    // En tu sistema: generá la clave y guardala con tu cliente ANTES de llamar, para que un
    // reintento (incluso después de un corte de tu proceso) no lo cree dos veces.
    try {
        $pspCustomer = $client->psp->customers->create(
            ['identity_verification' => $first],
            ['idempotency_key' => Uuid::v4()],
        );
    } catch (ConflictException|InvalidRequestException $e) {
        // CUSTOMER_ALREADY_EXISTS: ya es tu cliente (su id, en getData()). PARAMETER_INVALID: con
        // esa verificación no se puede crear (el motivo, en el mensaje).
        echo "No se pudo crear: {$e->getMessage()} ({$e->getErrorCode()})\n";
        exit(1);
    }
    echo "Cliente {$pspCustomer->id}: {$pspCustomer->status}\n";
    if ($pspCustomer->status !== PspCustomer::STATUS_ACTIVE) {
        // verifying o pending: seguimos en segundo plano.
        echo 'Te avisamos con psp.customer.activated, psp.customer.failed o '
            . "psp.customer.rejected.\n";
    }
    exit(0);
}

if ($command === 'ver' && $first !== null) {
    $pspCustomer = $client->psp->customers->retrieve($first);
    echo "Cliente {$pspCustomer->id} ({$pspCustomer->name}): {$pspCustomer->status}\n";
    echo 'CVU: ', $pspCustomer->cvu->account ?? '(todavía no tiene)', "\n";
    echo 'Alias: ', $pspCustomer->cvu->alias ?? '(todavía no tiene)', "\n";
    // Su saldo y lo que podés transferir ahora en su nombre: tu saldo manda.
    $customerBalance = $client->psp->customers->retrieveBalance($pspCustomer->id);
    echo 'Saldo: ', $customerBalance->available, "\n";
    echo 'Transferible: ', $customerBalance->transferable, "\n";
    // Sus últimas transferencias, recibidas y enviadas.
    $page = $client->psp->transfers->list(['customer' => $pspCustomer->id, 'limit' => 10]);
    foreach ($page as $pspTransfer) {
        echo "{$pspTransfer->created_at} {$pspTransfer->direction} {$pspTransfer->status} "
            . "{$pspTransfer->amount}\n";
    }
    exit(0);
}

if ($command === 'transferir' && $first !== null && $second !== null && $third !== null) {
    // La clave es obligatoria: en tu sistema, generala y guardala con la operación ANTES de
    // llamar, y reusala si reintentás. Así nunca se transfiere dos veces.
    try {
        $pspTransfer = $client->psp->transfers->create(
            ['customer' => $first, 'amount' => $third, 'destination' => $second],
            ['idempotency_key' => Uuid::v4()],
        );
    } catch (UnprocessableEntityException $e) {
        // INSUFFICIENT_BALANCE (tu saldo manda), CUSTOMER_INSUFFICIENT_BALANCE, DESTINATION_*.
        echo "No se pudo transferir: {$e->getMessage()} ({$e->getErrorCode()})\n";
        exit(1);
    }
    echo "Transferencia {$pspTransfer->id}: {$pspTransfer->status}\n";
    echo "Titular: {$pspTransfer->counterparty->name}\n";
    if ($pspTransfer->status === PspTransfer::STATUS_PENDING) {
        echo "Te avisamos con psp.transfer.sent o psp.transfer.failed.\n";
    }
    exit(0);
}

fwrite(STDERR, "Uso: php examples/operar-clientes-psp.php crear|ver|transferir …\n");
exit(1);
