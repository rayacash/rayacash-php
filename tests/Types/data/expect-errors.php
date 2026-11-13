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

use RayaCash\Checkout;
use RayaCash\ConnectOrder;
use RayaCash\IdentityVerification;
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

    // client_id es el número de la aplicación, pero va entre comillas: como string.
    // error: argument.type: Parameter #1 $params
    $client->oauth->token([
        'client_id' => 7650337728110670,
        'client_secret' => 'raya_cs_live_x',
        'code' => 'c0d3',
        'redirect_uri' => 'https://tienda.example.com/raya/callback',
    ]);

    // items es una lista de ítems, no un solo ítem.
    // error: argument.type: Parameter #1 $params
    $client->connect->orders->create(['items' => ['title' => 'x', 'quantity' => 1]]);

    // fee es un monto, no un bool.
    // error: argument.type: Parameter #1 $params
    $client->connect->orders->create(['items' => [], 'fee' => true]);

    // Por id, con el estilo global, es ConnectOrder::refundById(): refund() es del pago que ya
    // tenés.
    // error: method.staticCall: Static call to instance method RayaCash\ConnectOrder::refund()
    // error: argument.type: Parameter #1 $options
    ConnectOrder::refund('id');

    // La idempotency_key también es obligatoria en connect->payouts->create (como en transfers):
    // sin las opciones…
    // error: arguments.count: ConnectPayoutService::create() invoked with 1 parameter
    $client->connect->payouts->create(['amount' => 1, 'destination' => 'x']);

    // …o con las opciones, pero sin la clave.
    // error: argument.type: Parameter #2 $options
    $client->connect->payouts->create(['amount' => 1, 'destination' => 'x'], []);

    // destination va como string: un CBU como número pierde los ceros de adelante.
    // error: argument.type: Parameter #1 $params
    $client->connect->payouts->create(['amount' => 1, 'destination' => 123], $key);

    // Los filtros de la lista son los de la API: status es uno de los estados de un payout.
    // error: argument.type: Parameter #1 $params
    $client->connect->payouts->list(['status' => 'succeeded']);

    // Identidad: type es obligatorio en identity->verifications->create…
    // error: argument.type: Parameter #1 $params
    $client->identity->verifications->create([]);

    // …y es document o biometric.
    // error: argument.type: Parameter #1 $params
    $client->identity->verifications->create(['type' => 'selfie']);

    // La metadata de una verificación también es de string a string.
    // error: argument.type: Parameter #1 $params
    $client->identity->verifications->create(['type' => 'document', 'metadata' => ['plan' => 1]]);

    // Los filtros de la lista son los de la API: open no es un estado de la lista pública…
    // error: argument.type: Parameter #1 $params
    $client->identity->verifications->list(['status' => 'open']);

    // …y limit es un número.
    // error: argument.type: Parameter #1 $params
    $client->identity->verifications->list(['limit' => '10']);

    // Por id, con el estilo global, es IdentityVerification::cancelById(): cancel() es de la
    // verificación que ya tenés.
    // error: method.staticCall: RayaCash\IdentityVerification::cancel()
    // error: argument.type: Parameter #1 $options
    IdentityVerification::cancel('id');

    // El test helper del sandbox: amount es un monto, no un bool…
    // error: argument.type: Parameter #2 $params
    $client->testHelpers->payments->simulateTransfer('id', ['amount' => true]);

    // …y el id es un string.
    // error: argument.type: Parameter #1 $paymentId
    Payment::simulateTransfer(1042);

    // Los nombres de la 1.1.0 (alias deprecados) piden lo mismo que los nuevos: fee es un monto…
    // error: argument.type: Parameter #1 $params
    $client->checkout->orders->create(['items' => [], 'fee' => true]);

    // …y el id se llama $orderId, como en PHP: checkoutId (el de la 1.1.0) no tiene alias.
    // error: argument.missing: Missing parameter $orderId
    // error: argument.unknown: Unknown parameter $checkoutId
    Checkout::retrieve(checkoutId: 'id');
}
