<?php

declare(strict_types=1);

// Prueba manual contra la API real: solo consulta el saldo (GET /v1/balance).
//
// No crea ni mueve nada. Nunca corre en CI: lo corrés vos, con tu clave en la variable de
// entorno (nunca la pegues en un chat ni en el repositorio).
//
//   RAYA_SECRET_KEY=raya_sk_live_… scripts/dev.sh smoke
//   RAYA_SECRET_KEY=raya_sk_live_… php scripts/smoke-balance.php    (con PHP y composer install)

use RayaCash\RayaCash;
use RayaCash\RayaClient;

require dirname(__DIR__) . '/vendor/autoload.php';

RayaCash::setLog('info');
$balance = (new RayaClient())->balance->retrieve();
// var_export da el monto como lo escribe Python (1500, 1500.5): nunca (string) de un float.
printf(
    "Saldo disponible: \$%s %s (request %s)\n",
    var_export($balance->available, true),
    $balance->currency,
    $balance->getLastResponse()?->requestId,
);
