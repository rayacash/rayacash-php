<?php

declare(strict_types=1);

// Lo que PHPStan tiene que rechazar de la API pública, como los @ts-expect-error de
// test/types.test-d.ts en Node. Cada «// error: <identificador>[: <parte del mensaje>]» espera
// ese error en la próxima línea de código (uno por comentario), y ninguna otra línea de
// tests/Types/data puede dar errores: lo comprueba TypesTest::testPhpstanReportsTheExpectedErrors.
// Nunca se ejecuta.
//
// Lo que PHPStan no ve lo frena el SDK al llamar, antes de mandar nada:
// - la idempotency_key entre los parámetros, en un GET o en camelCase (los dos @ts-expect-error
//   de Node que no tienen par): las formas de array de PHPStan aceptan claves de más;
// - una opción desconocida de RayaClient, por lo mismo;
// - los argumentos de más, como retrieve($id, [], $opciones): func_num_args() vuelve variádicos
//   a esos métodos para PHPStan;
// - un bool en RayaCash::setTimeout() o setMaxNetworkRetries(), y un float (1.5) en
//   setMaxNetworkRetries(): la firma los acepta para rechazarlos también sin strict_types, y
//   PHPStan suma al @param los tipos de la firma.
// Esos casos están en EndpointsTest, ClientTest y GlobalTest.

namespace RayaCash\Tests\Types\Data;

use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\Webhook;

function expectErrors(RayaClient $client, Payment $payment, ?string $header, string $secret): void
{
    $key = ['idempotency_key' => 'k'];

    // La idempotency_key es obligatoria en transfers->create (Node): sin las opciones…
    // error: arguments.count: TransferService::create() invoked with 1 parameter
    $client->transfers->create(['amount' => 1, 'destination' => 'x']);

    // …o con las opciones, pero sin la clave.
    // error: argument.type: Parameter #2 $options
    $client->transfers->create(['amount' => 1, 'destination' => 'x'], []);

    // amount no acepta bool (Node).
    // error: argument.type: Parameter #1 $params
    $client->payments->create(['amount' => true]);

    // destination va como string: un CBU como número pierde los ceros de adelante.
    // error: argument.type: Parameter #1 $params
    $client->transfers->create(['amount' => 1, 'destination' => 123], $key);

    // Los filtros de la lista son los de la API: direction es inbound u outbound.
    // error: argument.type: Parameter #1 $params
    $client->transfers->list(['direction' => 'entrante']);

    // metadata es de string a string.
    // error: argument.type: Parameter #1 $params
    $client->payments->create(['amount' => 1, 'metadata' => ['cliente_id' => 8812]]);

    // La metadata de un pago puede volver con claves int (y con "0", "1"…, como lista, que
    // create rechaza): se manda de vuelta como objeto, (object) $payment->metadata.
    // error: argument.type: Parameter #1 $params
    $client->payments->create(['amount' => 1, 'metadata' => $payment->metadata]);

    // Por id, con el estilo global, es Payment::refundById(): refund() es del pago que ya tenés.
    // error: method.staticCall: Static call to instance method RayaCash\Payment::refund()
    // error: argument.type: Parameter #1 $options
    Payment::refund('id');

    // Los campos se documentan de solo lectura (@property-read), aunque el objeto sea mutable.
    // error: assign.propertyReadOnly: Property RayaCash\Payment::$status is not writable
    $payment->status = Payment::STATUS_REFUNDED;

    // Los webhooks se verifican sobre el body crudo, no sobre el JSON ya parseado.
    // error: argument.type: Parameter #1 $payload
    Webhook::constructEvent(['id' => 'evt_x'], $header, $secret);

    // La clave también puede ir en config: (sin el primer argumento), y es un string.
    // error: argument.type: $config
    new RayaClient(config: ['api_key' => 8812]);
}
