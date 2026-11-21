<?php

declare(strict_types=1);

// Facturador: emite una factura electrónica de ARCA (Factura B o C, según tu condición frente al
// IVA) a nombre de quien te compró, o muestra cómo salió una que ya creaste. Antes, configurá el
// Facturador en Negocios › Facturador, en el dashboard.
//
// ATENCIÓN: «emitir» crea una factura real, ante ARCA, con tu certificado y tu punto de venta. El
// Facturador no está disponible en el sandbox: va con tu clave secreta real.
//
//   export RAYA_SECRET_KEY=raya_sk_live_…
//   php examples/emitir-una-factura.php emitir <dni> <total>
//   php examples/emitir-una-factura.php ver <inv_…>

use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\PermissionDeniedException;
use RayaCash\Invoice;
use RayaCash\RayaClient;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new RayaClient(); // lee RAYA_SECRET_KEY
$command = $argv[1] ?? null;
$first = $argv[2] ?? null;
$second = $argv[3] ?? null;

if ($command === 'emitir' && $first !== null && $second !== null) {
    // La clave es obligatoria: en tu sistema, generala y guardala con la venta ANTES de llamar, y
    // reusala si reintentás. Así nunca se emiten dos facturas por una venta.
    try {
        $invoice = $client->invoices->create(
            ['dni' => $first, 'amount' => $second, 'metadata' => ['origen' => 'ejemplo']],
            ['idempotency_key' => Uuid::v4()],
        );
    } catch (PermissionDeniedException $e) {
        // FACTURADOR_NOT_READY: el motivo está en getData()['reason'] (not_configured, checking,
        // action_required, certificate_expired o disabled).
        $reason = $e->getData()['reason'] ?? null;
        $reason = is_string($reason) ? $reason : '-';
        echo "No se pudo emitir: {$e->getMessage()} ({$e->getErrorCode()}, {$reason})\n";
        exit(1);
    } catch (InvalidRequestException $e) {
        // PARAMETER_INVALID: un DNI que no es de 6 a 8 dígitos o un total que no vale.
        echo "No se pudo emitir: {$e->getMessage()} ({$e->getParam()})\n";
        exit(1);
    }
    echo "Factura {$invoice->id}: {$invoice->status}\n";
    echo "Te avisamos con invoice.issued, invoice.rejected o invoice.failed.\n";
    exit(0);
}

if ($command === 'ver' && $first !== null) {
    $invoice = $client->invoices->retrieve($first);
    echo "Factura {$invoice->type} {$invoice->id}: {$invoice->status}\n";
    // En una Factura B, el total incluye el IVA; en una C no hay IVA (vat_amount en null).
    $vat = $invoice->vat_amount ?? '-';
    echo "Total: {$invoice->amount} (neto {$invoice->net_amount}, IVA {$vat})\n";
    if ($invoice->status === Invoice::STATUS_ISSUED) {
        // El comprobante como lo muestra ARCA, con el punto de venta y el número: 00005-00000128.
        printf(
            "Comprobante %05d-%08d, del %s\n",
            $invoice->point_of_sale,
            $invoice->number,
            $invoice->issued_on,
        );
        echo "CAE {$invoice->cae}, vence el {$invoice->cae_expires_on}\n";
    } elseif ($invoice->failure_reason !== null) {
        echo "No se emitió: {$invoice->failure_reason->code}\n";
        foreach ($invoice->arca_messages as $message) {
            echo "ARCA ({$message->type} {$message->code}): {$message->message}\n";
        }
    }
    exit(0);
}

fwrite(STDERR, "Uso: php examples/emitir-una-factura.php emitir <dni> <total> | ver <inv_…>\n");
exit(1);
