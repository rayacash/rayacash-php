<?php

declare(strict_types=1);

// Identidad: crea una verificación de identidad y muestra el link para mandarle a la persona. Con
// el id de una que ya existe, muestra cómo salió (sin los datos de la persona: son personales).
//
// ATENCIÓN: correr este script sin argumentos crea una verificación en tu cuenta. Se cobra solo si
// una persona la completa y queda verified.
//
//   RAYA_SECRET_KEY=raya_sk_live_… php examples/verificar-una-identidad.php
//   RAYA_SECRET_KEY=raya_sk_live_… php examples/verificar-una-identidad.php <idv_…>

use RayaCash\IdentityVerification;
use RayaCash\RayaClient;
use RayaCash\Util\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = new RayaClient(); // lee RAYA_SECRET_KEY
$verificationId = $argv[1] ?? null;

if ($verificationId === null) {
    // En tu sistema: generá la clave y guardala con tu registro ANTES de llamar, para que un
    // reintento (incluso después de un corte de tu proceso) no cree otra verificación.
    $verification = $client->identity->verifications->create(
        [
            'type' => IdentityVerification::TYPE_DOCUMENT,
            'external_reference' => 'cliente-8812',
            'metadata' => ['plan' => 'pro'],
            'expires_in' => 86400, // un día
        ],
        ['idempotency_key' => Uuid::v4()],
    );
    echo "Verificación {$verification->id}: {$verification->status}\n";
    echo "Mandale este link a la persona: {$verification->url}\n";
    echo "Vence: {$verification->expires_at}\n";
    exit(0);
}

// El resultado que cuenta llega por webhook (identity.verification.*): esto es para consultarlo.
$verification = $client->identity->verifications->retrieve($verificationId);
echo "Verificación {$verification->id}: {$verification->status}\n";
echo "Lectura del DNI: {$verification->checks->document->status}\n";
echo "Prueba de vida: {$verification->checks->liveness->status}\n";
echo "Comparación de caras: {$verification->checks->face_match->status}\n";
echo "Registro (RENAPER): {$verification->checks->registry->status}\n";
if ($verification->status === IdentityVerification::STATUS_VERIFIED) {
    echo "Verificada: los datos de la persona están en \$verification->person.\n";
} elseif ($verification->status === IdentityVerification::STATUS_DECLINED) {
    // Decidí con el código, no con el mensaje (que puede cambiar).
    echo 'Rechazada: ', $verification->decline_reason?->code, "\n";
}
