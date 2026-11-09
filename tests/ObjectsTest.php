<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use RayaCash\Balance;
use RayaCash\Checkout;
use RayaCash\Event;
use RayaCash\Exception\ApiException;
use RayaCash\Internal\Hydrator;
use RayaCash\Internal\ObjectToken;
use RayaCash\ListObject;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\RayaCash;
use RayaCash\RayaObject;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\MockApi;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\User;

/**
 * Los objetos de la API: su clase, sus valores crudos, los campos que faltan, que se pueden
 * modificar, cómo se muestran y cómo se copian (test_objects.py).
 */
final class ObjectsTest extends TestCase
{
    public function testTransferIsATypedDictWithAttributeAccess(): void
    {
        $transfer = self::convertAs(Transfer::class, Fixtures::TRANSFER_INBOUND);
        // Python: isinstance(transfer, dict). Acá no es un array: se lee también con [].
        self::assertInstanceOf(\ArrayAccess::class, $transfer);
        self::assertSame(Fixtures::TRANSFER_INBOUND['id'], $transfer->id);
        self::assertSame(Fixtures::TRANSFER_INBOUND['id'], $transfer['id']);
        self::assertSame(130.41, $transfer->fee);
        self::assertSame(Transfer\Counterparty::class, $transfer->counterparty::class);
        self::assertNull($transfer->counterparty->alias);
        self::assertSame('0000123319112992888845', $transfer->counterparty->account);
    }

    public function testValuesStayAsRawJson(): void
    {
        $transfer = self::convertAs(Transfer::class, Fixtures::TRANSFER_INBOUND);
        self::assertSame(Fixtures::TRANSFER_INBOUND, self::jsonRoundTrip($transfer));
        // Los mismos bytes que el JSON de la API (en el mismo orden, como Object.keys en Node).
        self::assertSame(
            json_encode(Fixtures::TRANSFER_INBOUND, JSON_THROW_ON_ERROR),
            json_encode($transfer, JSON_THROW_ON_ERROR),
        );
        self::assertIsArray($transfer->toArray()['counterparty']);
        self::assertSame(Fixtures::TRANSFER_INBOUND, $transfer->toArray());
        self::assertIsInt($transfer->amount);
        self::assertIsFloat($transfer->net_amount);
        self::assertIsString($transfer->created_at);
    }

    public function testPaymentNestedObjectsAndPlainMetadata(): void
    {
        $pending = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        self::assertSame(Payment\BankTransfer::class, $pending->bank_transfer::class);
        self::assertSame('0000123322813466534007', $pending->bank_transfer->cvu);
        self::assertNull($pending->payer);
        self::assertSame(['cliente_id' => '8812'], $pending->metadata); // un array común
        $succeeded = self::convertAs(Payment::class, Fixtures::PAYMENT_SUCCEEDED);
        self::assertNotNull($succeeded->payer);
        self::assertSame(Payment\Payer::class, $succeeded->payer::class);
        self::assertSame('María Ejemplo', $succeeded->payer->name);
    }

    public function testCheckoutNestedObjectsAndPlainMetadata(): void
    {
        $pending = self::convertAs(Checkout::class, Fixtures::CHECKOUT_PENDING);
        self::assertSame(Checkout\BankTransfer::class, $pending->bank_transfer::class);
        self::assertSame('0000123390456538582824', $pending->bank_transfer->cvu);
        self::assertSame(Checkout\BackUrls::class, $pending->back_urls::class);
        self::assertSame(
            'https://tienda.example.com/pedidos/1042/error',
            $pending->back_urls->failure,
        );
        // Los ítems: una lista de Item.
        self::assertCount(1, $pending->items);
        self::assertSame(Checkout\Item::class, $pending->items[0]::class);
        self::assertSame(
            ['Pedido 1042', 1, 45000],
            [
                $pending->items[0]->title,
                $pending->items[0]->quantity,
                $pending->items[0]->unit_price,
            ],
        );
        self::assertNull($pending->payer);
        self::assertSame(['pedido_id' => '1042'], $pending->metadata); // un array común
        self::assertSame(Fixtures::CHECKOUT_PENDING, self::jsonRoundTrip($pending));
        self::assertSame(Fixtures::CHECKOUT_PENDING, $pending->toArray());
        $succeeded = self::convertAs(Checkout::class, Fixtures::CHECKOUT_SUCCEEDED);
        self::assertSame(Checkout\Payer::class, get_debug_type($succeeded->payer));
        self::assertSame('María Ejemplo', $succeeded->payer?->name);
        $empty = Checkout::constructFrom(['object' => 'checkout', 'items' => []]);
        self::assertSame([], $empty->items);
    }

    public function testUserNestedObjects(): void
    {
        $user = self::convertAs(User::class, Fixtures::USER);
        self::assertSame(User\Identification::class, get_debug_type($user->identification));
        self::assertSame(
            ['DNI', '34567890'],
            [$user->identification?->type, $user->identification?->number],
        );
        self::assertSame(User\Address::class, get_debug_type($user->address));
        self::assertSame('Rosario', $user->address?->city);
        self::assertNull($user->business);
        self::assertSame(User\Merchant::class, get_debug_type($user->merchant));
        self::assertSame(
            ['Almacén Ejemplo', null],
            [$user->merchant?->name, $user->merchant?->image],
        );
        self::assertTrue($user->identity_verified);
        self::assertSame(Fixtures::USER, $user->toArray());

        $business = self::convertAs(User::class, Fixtures::USER_BUSINESS);
        self::assertSame(User::TYPE_BUSINESS, $business->type);
        self::assertSame(User::IDENTIFICATION_CUIT, $business->identification?->type);
        self::assertSame(User\Business::class, get_debug_type($business->business));
        self::assertSame(
            User\Representative::class,
            get_debug_type($business->business?->representative),
        );
        self::assertSame('27345678901', $business->business?->representative?->tax_id);
        self::assertSame(Fixtures::USER_BUSINESS, $business->toArray());
    }

    /**
     * El access_token es un secreto, como la clave: (string), print_r y var_dump lo muestran
     * enmascarado. Para guardarlo están ->access_token, toArray() y json_encode(), enteros.
     */
    public function testOAuthTokenShowsTheAccessTokenMasked(): void
    {
        $token = self::convertAs(OAuthToken::class, Fixtures::OAUTH_TOKEN);
        self::assertSame(Fixtures::ACCESS_TOKEN, $token->access_token);
        self::assertSame(Fixtures::OAUTH_TOKEN, $token->toArray());
        self::assertSame(
            json_encode(Fixtures::OAUTH_TOKEN, JSON_THROW_ON_ERROR),
            json_encode($token, JSON_THROW_ON_ERROR),
        );
        $masked = 'raya_at_live_…9hJk';
        self::assertSame(
            self::sortedKeys([...Fixtures::OAUTH_TOKEN, 'access_token' => $masked]),
            json_decode((string) $token, true, 512, JSON_THROW_ON_ERROR),
        );
        $printed = print_r($token, true);
        self::assertStringStartsWith("RayaCash\\OAuthToken Object\n(\n", $printed);
        self::assertStringContainsString("[access_token] => {$masked}", $printed);
        ob_start();
        var_dump($token);
        $dumped = (string) ob_get_clean();
        self::assertStringContainsString($masked, $dumped);
        $secret = substr(Fixtures::ACCESS_TOKEN, strlen('raya_at_live_'));
        foreach ([(string) $token, $printed, $dumped] as $shown) {
            self::assertStringNotContainsString($secret, $shown);
        }
        // Enmascarar no cambia el objeto.
        self::assertSame(Fixtures::ACCESS_TOKEN, $token->access_token);
        self::assertSame(Fixtures::OAUTH_TOKEN, $token->toArray());
        // Sin access_token (uno armado a mano, o vacío), nada que enmascarar.
        self::assertSame('{}', (string) OAuthToken::constructFrom([]));
        $empty = OAuthToken::constructFrom(['access_token' => '']);
        self::assertSame(['access_token' => ''], $empty->__debugInfo());
        self::assertSame("{\n  \"access_token\": \"\"\n}", (string) $empty);
    }

    public function testEventHydratesACheckout(): void
    {
        $event = Event::constructFrom(Fixtures::EVENT_CHECKOUT_SUCCEEDED);
        self::assertSame(Event::TYPE_CHECKOUT_SUCCEEDED, $event->type);
        $object = $event->data->object;
        self::assertSame(Checkout::class, get_debug_type($object));
        self::assertInstanceOf(Checkout::class, $object);
        self::assertSame(['succeeded', 500], [$object->status, $object->fee]);
        self::assertSame(Checkout\Item::class, get_debug_type($object->items[0]));
    }

    public function testMetadataKeysCanShadowDictMethods(): void
    {
        $payment = self::convertAs(
            Payment::class,
            [...Fixtures::PAYMENT_PENDING, 'metadata' => ['items' => '3', 'keys' => 'x']],
        );
        self::assertSame('3', $payment->metadata['items']);
        // Node: las claves de la metadata son tuyas, aunque parezcan un objeto de la API.
        $payment = self::convertAs(
            Payment::class,
            [...Fixtures::PAYMENT_PENDING, 'metadata' => ['object' => 'payment', 'keys' => 'x']],
        );
        self::assertSame(['object' => 'payment', 'keys' => 'x'], $payment->metadata);
    }

    public function testUnknownFieldsAndObjectsAreKept(): void
    {
        $transfer = self::convertAs(Transfer::class, [
            ...Fixtures::TRANSFER_INBOUND,
            'nuevo_campo' => ['a' => 1],
            'otro' => [['object' => 'algo_nuevo', 'x' => 2]],
        ]);
        // Con -> también (PHPStan no conoce los campos nuevos: en tu código, ['nuevo_campo']).
        // @phpstan-ignore property.notFound (a propósito: un campo que el SDK no declara)
        $nuevo = $transfer->nuevo_campo;
        self::assertInstanceOf(RayaObject::class, $nuevo);
        self::assertSame(RayaObject::class, $nuevo::class);
        self::assertSame(['a' => 1], $nuevo->toArray());
        $otro = $transfer['otro'];
        self::assertIsArray($otro);
        self::assertInstanceOf(RayaObject::class, $otro[0]);
        self::assertSame(RayaObject::class, $otro[0]::class);
        self::assertSame(2, $otro[0]['x']);
        self::assertSame(RayaObject::class, get_debug_type(self::convert(['object' => 'nuevo'])));
        self::assertSame([1, 'x', null], self::convert([1, 'x', null]));
    }

    public function testMissingFieldsRaiseAttributeError(): void
    {
        // El AttributeError de Python es un \OutOfBoundsException.
        $balance = self::convertAs(
            Balance::class,
            ['object' => 'balance', 'available' => 1, 'currency' => 'ARS'],
        );
        $message = "Balance no tiene el campo 'inexistente'. Campos: available, currency, object.";
        $error = self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $balance->inexistente,
        );
        self::assertSame($message, $error->getMessage());
        // Python: balance._privado también falla. En PHP no hay regla para el «_»: es un campo.
        self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $balance->_privado,
            "Balance no tiene el campo '_privado'.",
        );
        // Python: balance["inexistente"] es KeyError. Acá, el mismo error que con ->.
        $error = self::assertThrows(
            \OutOfBoundsException::class,
            static fn() => $balance['inexistente'],
        );
        self::assertSame($message, $error->getMessage());
        // getattr(balance, "inexistente", "default") en Python: ?? e isset nunca fallan.
        self::assertSame('default', $balance->inexistente ?? 'default');
        self::assertSame('default', $balance['inexistente'] ?? 'default');
        self::assertFalse(isset($balance->inexistente));
        self::assertFalse(isset($balance['inexistente']));

        // Un campo en null existe: se lee sin error, pero isset y ?? lo ven como ausente.
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        self::assertNull($payment->paid_at);
        self::assertFalse(isset($payment->paid_at));
        self::assertSame('todavía no', $payment->paid_at ?? 'todavía no');
        // El nombre corto de la clase, también en los sub-objetos; (ninguno) si no hay campos.
        $transfer = self::convertAs(Transfer::class, Fixtures::TRANSFER_INBOUND);
        self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $transfer->counterparty->cbu,
            "Counterparty no tiene el campo 'cbu'. Campos: account, alias, name, tax_id.",
        );
        $empty = RayaObject::constructFrom([]);
        self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $empty->x,
            "RayaObject no tiene el campo 'x'. Campos: (ninguno).",
        );
        // Un número no es un campo que falta: con [0] es el mismo error, con otra cosa, TypeError.
        self::assertThrows(
            \OutOfBoundsException::class,
            static fn() => $balance[0],
            "Balance no tiene el campo '0'.",
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore offsetAccess.notFound (lo que haría alguien sin PHPStan)
            static fn() => $balance[1.5],
            "Los campos de Balance se leen con su nombre, como \$obj['status'], no con float.",
        );
    }

    public function testSettingAndDeletingFields(): void
    {
        $object = RayaObject::constructFrom([]);
        // @phpstan-ignore property.notFound (a propósito: RayaObject no declara campos)
        $object->nombre = 'x';
        self::assertSame(['nombre' => 'x'], self::fieldsOf($object));
        unset($object->nombre);
        self::assertSame([], self::fieldsOf($object));
        // Python: borrarlo otra vez es AttributeError. En PHP, como unset en un array: nada.
        unset($object->nombre);
        self::assertSame([], self::fieldsOf($object));
        // Python: un atributo con «_» no es un dato. En PHP no hay esa regla: es un campo más.
        // @phpstan-ignore property.notFound (a propósito: RayaObject no declara campos)
        $object->_interno = 1;
        self::assertSame(['_interno' => 1], self::fieldsOf($object));
        self::assertSame(1, $object['_interno']);
        unset($object->_interno);
        self::assertSame([], self::fieldsOf($object));

        // Con [] es lo mismo; sin nombre de campo, no.
        $object['nombre'] = 'y';
        // @phpstan-ignore property.notFound (a propósito: RayaObject no declara campos)
        self::assertSame('y', $object->nombre);
        self::assertTrue(isset($object['nombre']));
        unset($object['nombre']);
        self::assertSame([], self::fieldsOf($object));
        self::assertThrows(
            \TypeError::class,
            static function () use ($object): void {
                $object[] = 'x';
            },
            "RayaObject no acepta \$obj[] = …: poné el nombre del campo, como \$obj['campo'] = "
                . '$valor.',
        );
        self::assertSame([], self::fieldsOf($object));
    }

    public function testStrAndRepr(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        // str() en Python: JSON con las claves ordenadas y 2 espacios de sangría.
        self::assertSame(
            self::sortedKeys(Fixtures::PAYMENT_PENDING),
            json_decode((string) $payment, true, 512, JSON_THROW_ON_ERROR),
        );
        $object = RayaObject::constructFrom([
            'b' => ['y' => 1, 'x' => 'María/Ana'],
            'a' => 25000.0,
            'c' => null,
            'd' => new \stdClass(),
            'e' => [],
            'f' => [2, 1],
        ]);
        self::assertSame(
            "{\n"
                . "  \"a\": 25000.0,\n"
                . "  \"b\": {\n"
                . "    \"x\": \"María/Ana\",\n"
                . "    \"y\": 1\n"
                . "  },\n"
                . "  \"c\": null,\n"
                . "  \"d\": {},\n"
                . "  \"e\": [],\n"
                . "  \"f\": [\n"
                . "    2,\n"
                . "    1\n"
                . "  ]\n"
                . '}',
            (string) $object,
        );
        self::assertSame('{}', (string) RayaObject::constructFrom([]));
        // repr() en Python («<Payment payment id=… at 0x…> JSON: {…}»): en PHP, print_r y
        // var_dump, que muestran la clase y los campos.
        self::assertStringStartsWith(
            "RayaCash\\Payment Object\n(\n    [id] => " . Fixtures::PAYMENT_ID . "\n",
            print_r($payment, true),
        );
        self::assertSame(
            "RayaCash\\RayaObject Object\n(\n)\n",
            print_r(RayaObject::constructFrom([]), true),
        );
    }

    /**
     * sort_keys en Python ordena las claves en todos los niveles, también las de un dict que le
     * asignaste vos: acá, las de un array asociativo (las listas quedan en su orden).
     */
    public function testStrSortsTheKeysOfWhatYouSetToo(): void
    {
        $object = RayaObject::constructFrom(['b' => 1]);
        $object['a'] = ['z' => 1, 'y' => ['x' => [3, 2], 'w' => null]];
        self::assertSame(
            "{\n"
                . "  \"a\": {\n"
                . "    \"y\": {\n"
                . "      \"w\": null,\n"
                . "      \"x\": [\n"
                . "        3,\n"
                . "        2\n"
                . "      ]\n"
                . "    },\n"
                . "    \"z\": 1\n"
                . "  },\n"
                . "  \"b\": 1\n"
                . '}',
            (string) $object,
        );
    }

    public function testPickleCopyAndDeepcopy(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_SUCCEEDED);
        // pickle es serialize.
        $restored = unserialize(serialize($payment));
        self::assertInstanceOf(Payment::class, $restored);
        self::assertSame(Payment::class, $restored::class);
        self::assertNotNull($restored->payer);
        self::assertSame(Payment\Payer::class, $restored->payer::class);
        self::assertEquals($payment, $restored);
        self::assertSame($payment->toArray(), $restored->toArray());
        // restored._requestor en Python: acá vive en el token (ObjectToken, interno).
        self::assertNull(ObjectToken::of($restored)->requestor());

        // copy.copy y copy.deepcopy son clone: copia también los sub-objetos.
        $shallow = clone $payment;
        self::assertSame(Payment::class, $shallow::class);
        self::assertEquals($payment, $shallow);

        $deep = clone $payment;
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $deep->metadata['cliente_id'] = 'otro';
        self::assertSame('8812', $payment->metadata['cliente_id']);
        $payer = $deep->payer;
        self::assertNotNull($payer);
        self::assertNotSame($payment->payer, $payer);
        $payer['name'] = 'Otra Persona';
        self::assertSame('Otra Persona', $deep->payer?->name);
        self::assertSame('María Ejemplo', $payment->payer?->name);
        // También los objetos dentro de una lista.
        $page = self::convertAs(ListObject::class, Fixtures::TRANSFER_LIST);
        $copy = clone $page;
        $transfer = $copy->data[0];
        self::assertInstanceOf(Transfer::class, $transfer);
        $transfer['description'] = 'Otra';
        $original = $page->data[0];
        self::assertInstanceOf(Transfer::class, $original);
        self::assertNotSame($original, $transfer);
        self::assertSame('Alquiler octubre', $original->description);
    }

    public function testEventHydratesItsObject(): void
    {
        $event = Event::constructFrom(Fixtures::EVENT_PAYMENT_SUCCEEDED);
        self::assertSame(Event::class, $event::class);
        self::assertSame(Event\Data::class, $event->data::class);
        $object = $event->data->object;
        self::assertSame(Payment::class, $object::class);
        self::assertInstanceOf(Payment::class, $object);
        self::assertSame('succeeded', $object->status);
        $testEvent = Event::constructFrom([
            'id' => 'evt_x',
            'object' => 'event',
            'type' => 'webhook.test',
            'data' => ['object' => ['object' => 'test']],
        ]);
        self::assertSame(RayaObject::class, $testEvent->data->object::class);
    }

    public function testListObjectIteratesItsData(): void
    {
        $page = self::convertAs(ListObject::class, Fixtures::TRANSFER_LIST);
        $first = Fixtures::TRANSFER_LIST['data'][0]['id'];
        $second = Fixtures::TRANSFER_LIST['data'][1]['id'];
        $ids = [];
        foreach ($page as $transfer) {
            self::assertInstanceOf(Transfer::class, $transfer);
            $ids[] = $transfer->id;
        }
        self::assertSame([$first, $second], $ids);
        self::assertCount(2, $page);
        // next(reversed(page)) en Python: el último de la página.
        $reversed = array_reverse(iterator_to_array($page));
        self::assertInstanceOf(Transfer::class, $reversed[0]);
        self::assertSame($second, $reversed[0]->id);
        self::assertSame(
            [Transfer::class, Transfer::class],
            array_map(get_debug_type(...), $page->data),
        );
        self::assertTrue($page['has_more']);
        self::assertSame(Fixtures::NEXT_CURSOR, $page->next_cursor);
        self::assertSame(Fixtures::TRANSFER_LIST, self::jsonRoundTrip($page));
        self::assertSame(Fixtures::TRANSFER_LIST, $page->toArray());
        // Python: page[0] es KeyError y dice dónde están los elementos.
        $error = self::assertThrows(\OutOfBoundsException::class, static fn() => $page[0]);
        self::assertSame(
            'Una ListObject no se indexa con 0: los elementos están en ->data (por ejemplo, '
                . '$page->data[0]).',
            $error->getMessage(),
        );
        self::assertFalse(isset($page[0]));
        // PHP: lo que no es un nombre ni un int da el TypeError de cualquier objeto (como en
        // testMissingFieldsRaiseAttributeError).
        foreach (['float' => 1.5, 'bool' => true] as $type => $offset) {
            $error = self::assertThrows(
                \TypeError::class,
                // @phpstan-ignore offsetAccess.notFound (lo que haría alguien sin PHPStan)
                static fn() => $page[$offset],
            );
            self::assertSame(
                "Los campos de ListObject se leen con su nombre, como \$obj['status'], no con "
                    . "{$type}.",
                $error->getMessage(),
            );
        }
    }

    public function testListObjectWithoutData(): void
    {
        $page = ListObject::constructFrom(['object' => 'list']);
        self::assertSame([], iterator_to_array($page));
        self::assertCount(0, $page);
    }

    // --- De Node ----------------------------------------------------------------------------

    /**
     * Como el «__proto__» de Node: un campo del JSON que se llama como las propiedades internas
     * de RayaObject (values, token) es un dato más y no pisa nada.
     */
    public function testJsonFieldsNamedLikeTheInternalsStayAsData(): void
    {
        $client = $this->api->client();
        $this->api->add(
            200,
            ['object' => 'transfer', 'id' => 't', 'values' => [1], 'token' => 'x'],
        );
        $transfer = $client->transfers->retrieve('t');
        self::assertSame(Transfer::class, $transfer::class);
        self::assertSame([1], $transfer['values']);
        self::assertSame('x', $transfer['token']);
        foreach (['values' => [1], 'token' => 'x'] as $name => $value) {
            self::assertTrue(isset($transfer->{$name}));
            self::assertSame($value, $transfer->{$name});
        }
        self::assertSame(
            ['object' => 'transfer', 'id' => 't', 'values' => [1], 'token' => 'x'],
            $transfer->toArray(),
        );
        self::assertSame('req_test00000000000000000001', $transfer->getLastResponse()?->requestId);
    }

    /** Como console.log en Node: los dumps muestran la clase y los campos, no el cliente. */
    public function testDumpsShowTheFieldsButNotTheClientOrTheResponse(): void
    {
        $this->api->add(200, Fixtures::PAYMENT_PENDING);
        $payment = $this->api->client()->payments->retrieve(Fixtures::PAYMENT_ID);
        self::assertNotNull($payment->getLastResponse());
        $printed = print_r($payment, true);
        self::assertStringStartsWith("RayaCash\\Payment Object\n(\n", $printed);
        self::assertStringContainsString('[status] => pending', $printed);
        ob_start();
        var_dump($payment);
        $dumped = (string) ob_get_clean();
        // Sin mirar el formato exacto: con Xdebug en modo develop, var_dump es otro.
        self::assertStringContainsString('RayaCash\Payment', $dumped);
        self::assertStringContainsString('string(7) "pending"', $dumped);
        $exported = var_export($payment, true);
        self::assertStringContainsString("'status' => 'pending'", $exported);
        foreach ([$printed, $dumped, $exported, serialize($payment)] as $shown) {
            self::assertStringNotContainsString('req_test', $shown);
            self::assertStringNotContainsString('Requestor', $shown);
            self::assertStringNotContainsString('RayaResponse', $shown);
            self::assertStringNotContainsString('raya_sk_live_', $shown);
        }
    }

    public function testConstructFromNeedsAnObjectAndHasNoResponse(): void
    {
        $payment = Payment::constructFrom(['object' => 'payment', 'id' => 'p']);
        self::assertSame(Payment::class, $payment::class);
        self::assertNull($payment->getLastResponse());
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => Payment::constructFrom(['a', 'b']),
        );
        self::assertSame(
            'constructFrom necesita un objeto JSON (un array asociativo o un stdClass), no una '
                . 'lista.',
            $error->getMessage(),
        );
        // [] es el objeto vacío ({}) y un stdClass también sirve.
        self::assertSame([], Payment::constructFrom([])->toArray());
        self::assertSame('p', Payment::constructFrom((object) ['id' => 'p'])->id);
        // La clase es la que llamás, como construct_from en Python.
        self::assertSame(Payment::class, Payment::constructFrom(['object' => 'transfer'])::class);
    }

    // --- Solo de PHP ------------------------------------------------------------------------

    /** json_decode(…, false) distingue {} de []: json_encode($obj) da el JSON tal cual vino. */
    public function testEmptyObjectsStayObjects(): void
    {
        $json = '{"object":"payment","id":"p","metadata":{},"bank_transfer":{},"nuevo":{},'
            . '"lista":[],"anidado":{"vacio":{},"lista":[]}}';
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $payment = Hydrator::convert($decoded, null, null);
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame($json, json_encode($payment, JSON_THROW_ON_ERROR));
        // toArray() da arrays comunes: ahí {} y [] son lo mismo (declarado en el CHANGELOG).
        self::assertSame(
            [
                'object' => 'payment',
                'id' => 'p',
                'metadata' => [],
                'bank_transfer' => [],
                'nuevo' => [],
                'lista' => [],
                'anidado' => ['vacio' => [], 'lista' => []],
            ],
            $payment->toArray(),
        );
        // La metadata vacía de un pago armado a mano también sale como objeto.
        self::assertSame(
            '{"object":"payment","metadata":{}}',
            json_encode(Payment::constructFrom(['object' => 'payment', 'metadata' => []])),
        );

        // Lo mismo con las claves "0", "1"…: PHP las convierte en una lista, que toArray() deja
        // así (declarado en el CHANGELOG). json_encode y (string) conservan el objeto.
        $json = '{"object":"payment","metadata":{"0":"a","1":"b"},"nuevo":{"0":"x"}}';
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $payment = Hydrator::convert($decoded, null, null);
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame($json, json_encode($payment, JSON_THROW_ON_ERROR));
        self::assertSame(
            ['object' => 'payment', 'metadata' => ['a', 'b'], 'nuevo' => ['x']],
            $payment->toArray(),
        );
        self::assertStringContainsString(
            "  \"metadata\": {\n    \"0\": \"a\",\n    \"1\": \"b\"\n  },\n"
                . "  \"nuevo\": {\n    \"0\": \"x\"\n  },\n",
            (string) $payment,
        );
    }

    /**
     * (string) $obj escribe los floats como json.dumps en Python, con cualquier
     * serialize_precision (17 era el de fábrica antes de PHP 7.1): 1500.1 y no
     * 1500.0999999999999, 1e-07 y no 1.0e-7, Infinity y NaN. U+2028 y U+2029 quedan tal cual,
     * como con ensure_ascii=False.
     */
    public function testStrDoesNotDependOnPhpIni(): void
    {
        $object = RayaObject::constructFrom([
            'amount' => 1500.1,
            'fee' => 0.1,
            'total' => 25000.0,
            'tiny' => 1e-7,
            'big' => 1e16,
            'zero' => -0.0,
            'limits' => [INF, -INF, NAN],
            'description' => "Línea 1\u{2028}Línea 2\u{2029}",
        ]);
        // El str() de Python con los mismos valores.
        $python = "{\n"
            . "  \"amount\": 1500.1,\n"
            . "  \"big\": 1e+16,\n"
            . "  \"description\": \"Línea 1\u{2028}Línea 2\u{2029}\",\n"
            . "  \"fee\": 0.1,\n"
            . "  \"limits\": [\n"
            . "    Infinity,\n"
            . "    -Infinity,\n"
            . "    NaN\n"
            . "  ],\n"
            . "  \"tiny\": 1e-07,\n"
            . "  \"total\": 25000.0,\n"
            . "  \"zero\": -0.0\n"
            . '}';
        $ini = ini_get('serialize_precision');
        try {
            foreach (['-1', '17'] as $precision) {
                ini_set('serialize_precision', $precision);
                self::assertSame($python, (string) $object, "serialize_precision = {$precision}");
            }
        } finally {
            ini_set('serialize_precision', (string) $ini);
        }

        // Lo que no es del SDK (una fecha que asignaste vos) lo escribe json_encode, con la misma
        // sangría. json.dumps de Python no puede.
        $object = RayaObject::constructFrom(['b' => 1]);
        $object['a'] = new \DateTimeImmutable('2026-10-24 14:00:00', new \DateTimeZone('UTC'));
        self::assertSame(
            "{\n"
                . "  \"a\": {\n"
                . "    \"date\": \"2026-10-24 14:00:00.000000\",\n"
                . "    \"timezone_type\": 3,\n"
                . "    \"timezone\": \"UTC\"\n"
                . "  },\n"
                . "  \"b\": 1\n"
                . '}',
            (string) $object,
        );
    }

    /**
     * Los campos vuelven por referencia: un array o un sub-objeto se cambia en el lugar, como en
     * Python y Node, sin el «Indirect modification» de PHP.
     */
    public function testNestedValuesChangeInPlace(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $payment->metadata['pedido'] = '1042';
        // @phpstan-ignore offsetAccess.nonOffsetAccessible ($payment['metadata'] es mixed)
        $payment['metadata']['canal'] = 'web';
        self::assertSame(
            ['cliente_id' => '8812', 'pedido' => '1042', 'canal' => 'web'],
            $payment->metadata,
        );
        self::assertStringContainsString(
            '"metadata":{"cliente_id":"8812","pedido":"1042","canal":"web"}',
            json_encode($payment, JSON_THROW_ON_ERROR),
        );
        $bankTransfer = $payment->bank_transfer; // un objeto: es el mismo, no una copia
        $bankTransfer['alias'] = null;
        self::assertSame(
            ['cvu' => '0000123322813466534007', 'alias' => null],
            $payment->toArray()['bank_transfer'],
        );
        // Lo que guardás en una variable es una copia: el pago no cambia.
        $metadata = $payment->metadata;
        $metadata['otro'] = 'x';
        self::assertArrayNotHasKey('otro', $payment->metadata);

        $page = self::convertAs(ListObject::class, Fixtures::TRANSFER_LIST);
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $page->data[] = Transfer::constructFrom(Fixtures::TRANSFER_OUTBOUND_PENDING);
        self::assertCount(3, $page);

        // Un clon de un objeto ya cambiado por referencia sigue siendo independiente.
        $copy = clone $payment;
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->metadata['pedido'] = '2000';
        $copyBankTransfer = $copy->bank_transfer;
        $copyBankTransfer['alias'] = 'otro.alias';
        self::assertSame('1042', $payment->metadata['pedido']);
        self::assertNull($payment->bank_transfer->alias);
    }

    /**
     * Un clon no comparte nada con el original, como copy y deepcopy en Python, aunque tengas
     * una referencia a uno de sus campos ($ref = &$payment->bank_transfer, o lo que deja un
     * foreach por referencia): sin eso, el clon escribiría a través de ella.
     */
    public function testCloneIsIndependentEvenWithReferences(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_SUCCEEDED);
        $original = $payment->bank_transfer;
        $bankTransfer = &$payment->bank_transfer;
        $metadata = &$payment->metadata;
        $clientId = &$payment->metadata['cliente_id'];
        $status = &$payment->status;

        $copy = clone $payment;
        self::assertSame($original, $payment->bank_transfer);
        self::assertNotSame($original, $copy->bank_transfer);
        $copyBankTransfer = $copy->bank_transfer;
        $copyBankTransfer['alias'] = 'otro.alias';
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->metadata['canal'] = 'web';
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->metadata['cliente_id'] = 'otro';
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->status = Payment::STATUS_REFUNDED;
        self::assertSame(Fixtures::PAYMENT_SUCCEEDED, $payment->toArray());
        self::assertSame('otro.alias', $copy->bank_transfer->alias);
        self::assertSame(['cliente_id' => 'otro', 'canal' => 'web'], $copy->metadata);
        self::assertSame(Payment::STATUS_REFUNDED, $copy->status);

        // Las referencias siguen siendo del original.
        $bankTransfer = null;
        $metadata['pedido'] = '1042';
        $clientId = '9000';
        $status = Payment::STATUS_FAILED;
        self::assertSame(
            [null, ['cliente_id' => '9000', 'pedido' => '1042'], Payment::STATUS_FAILED],
            [$payment['bank_transfer'], $payment->metadata, $payment->status],
        );
        self::assertSame($copyBankTransfer, $copy->bank_transfer);
        self::assertSame(['cliente_id' => 'otro', 'canal' => 'web'], $copy->metadata);
        self::assertSame(Payment::STATUS_REFUNDED, $copy->status);
    }

    public function testSerializeDropsTheClientAndCloneKeepsIt(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        $response = $payment->getLastResponse();
        self::assertNotNull($response);
        $refund = '/v1/payments/' . Fixtures::PAYMENT_ID . '/refund';

        // clone conserva el cliente y la respuesta, como copy en Python.
        $copy = clone $payment;
        self::assertSame($response, $copy->getLastResponse());
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        self::assertSame('refunded', $copy->refund()->status);
        self::assertSame(['POST', $refund], [$this->api->last()->method, $this->api->last()->path]);
        self::assertSame(
            'Bearer ' . Fixtures::TEST_KEY,
            $this->api->last()->header('authorization'),
        );

        // Los sub-objetos de un clon también: un pago dentro de otro objeto.
        $this->api->add(200, ['object' => 'algo_nuevo', 'pago' => Fixtures::PAYMENT_SUCCEEDED]);
        $wrapper = $client->rawRequest('GET', '/v1/algo_nuevo');
        $nested = (clone $wrapper)['pago'];
        self::assertInstanceOf(Payment::class, $nested);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $nested->refund();
        self::assertSame($refund, $this->api->last()->path);
        self::assertCount(4, $this->api->requests);

        // serialize no lleva el cliente ni la respuesta, como pickle: uno deserializado usa el
        // estilo global.
        $restored = unserialize(serialize($payment));
        self::assertInstanceOf(Payment::class, $restored);
        self::assertNull($restored->getLastResponse());
        $global = new MockApi();
        RayaCash::setApiKey(Fixtures::OTHER_KEY);
        RayaCash::setHttpClient($global);
        $global->add(200, Fixtures::PAYMENT_REFUNDED);
        $restored->refund();
        self::assertCount(1, $global->requests);
        self::assertSame($refund, $global->last()->path);
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $global->last()->header('authorization'));
        self::assertCount(4, $this->api->requests);
    }

    /** Un objeto serializado sin sus datos (por otra versión, o tocado a mano) vuelve vacío. */
    public function testUnserializeWithoutTheDataGivesAnEmptyObject(): void
    {
        $payloads = [
            'O:16:"RayaCash\Payment":0:{}',
            'O:16:"RayaCash\Payment":1:{s:6:"values";s:4:"otro";}',
        ];
        foreach ($payloads as $payload) {
            $payment = unserialize($payload);
            self::assertInstanceOf(Payment::class, $payment);
            self::assertSame([], $payment->toArray());
            self::assertSame('{}', json_encode($payment));
            self::assertNull($payment->getLastResponse());
        }
    }

    /**
     * constructFrom acepta objetos del SDK adentro, como en Python (donde un RayaObject es un
     * dict): sirve para armar en tus tests un evento con un pago que ya tenés. Adentro quedan
     * copias, con la clase de su "object" o la de su campo.
     */
    public function testConstructFromAcceptsObjectsOfTheSdkInside(): void
    {
        $payment = Payment::constructFrom(Fixtures::PAYMENT_SUCCEEDED);
        $event = Event::constructFrom(
            ['data' => ['object' => $payment]] + Fixtures::EVENT_PAYMENT_SUCCEEDED,
        );
        $object = $event->data->object;
        self::assertInstanceOf(Payment::class, $object);
        self::assertNotSame($payment, $object);
        self::assertSame($payment->toArray(), $object->toArray());
        self::assertSame(Payment\Payer::class, get_debug_type($object->payer));
        $object['status'] = 'refunded';
        self::assertSame('succeeded', $payment->status);

        $payer = RayaObject::constructFrom(['name' => 'Ana']);
        $other = Payment::constructFrom(['object' => 'payment', 'payer' => $payer]);
        self::assertSame(Payment\Payer::class, get_debug_type($other->payer));
        self::assertSame('Ana', $other->payer?->name);
    }

    /**
     * La clase sale de "object"; si no está o el SDK no lo conoce, de lo que esperaba el método
     * (solo en el primer nivel). Otro tipo conocido no se puede devolver: respuesta inválida.
     */
    public function testUnknownObjectTypesHydrateAsTheExpectedClass(): void
    {
        $client = $this->api->client();
        $this->api->add(200, ['available' => 1, 'currency' => 'ARS']);
        self::assertSame(Balance::class, $client->balance->retrieve()::class);
        $this->api->add(200, ['object' => 'balance_v2', 'available' => 1, 'nuevo' => ['x' => 1]]);
        $balance = $client->balance->retrieve();
        self::assertSame(Balance::class, $balance::class);
        self::assertSame(RayaObject::class, get_debug_type($balance['nuevo']));

        $calls = [
            'un saldo' => [Fixtures::PAYMENT_PENDING, static fn() => $client->balance->retrieve()],
            'una transferencia' => [
                Fixtures::BALANCE,
                static fn() => $client->transfers->retrieve('t'),
            ],
            'un pago' => [
                Fixtures::TRANSFER_INBOUND,
                static fn() => $client->payments->retrieve('p'),
            ],
            'un access_token' => [
                Fixtures::USER,
                static fn() => $client->oauth->token([
                    'client_id' => Fixtures::CLIENT_ID,
                    'client_secret' => Fixtures::CLIENT_SECRET,
                    'code' => Fixtures::CODE,
                    'redirect_uri' => Fixtures::REDIRECT_URI,
                ]),
            ],
            'un usuario' => [
                Fixtures::CHECKOUT_PENDING,
                static fn() => $client->users->retrieve('me'),
            ],
            'un checkout' => [
                Fixtures::OAUTH_TOKEN,
                static fn() => $client->checkout->orders->retrieve('c'),
            ],
        ];
        foreach ($calls as $noun => [$body, $call]) {
            $this->api->add(200, $body);
            $error = self::assertThrows(
                ApiException::class,
                $call,
                "La API respondió algo que no es {$noun}.",
                'INVALID_RESPONSE',
            );
            self::assertSame("La API respondió algo que no es {$noun}.", $error->getMessage());
            self::assertSame(200, $error->getHttpStatus());
            // Con la respuesta que lo causó, como cualquier error de la API.
            self::assertSame(
                sprintf('req_test%020d', count($this->api->requests)),
                $error->getRequestId(),
            );
            self::assertSame($body, json_decode((string) $error->getHttpBody(), true));
        }
        self::assertCount(8, $this->api->requests); // no se reintenta
    }

    /**
     * Como convert_to_raya_object en Python: el valor, pasado por JSON y decodificado como lo hace
     * el SDK (los objetos como stdClass), convertido en objetos del SDK.
     */
    private static function convert(mixed $value): mixed
    {
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        return Hydrator::convert(json_decode($json, false, 512, JSON_THROW_ON_ERROR), null, null);
    }

    /**
     * convert(), que tiene que dar exactamente esa clase (como `type(x) is Clase` en Python).
     *
     * @template T of RayaObject
     * @param class-string<T> $class
     * @return T
     */
    private static function convertAs(string $class, mixed $value): RayaObject
    {
        $object = self::convert($value);
        self::assertSame($class, get_debug_type($object));
        self::assertInstanceOf($class, $object);
        return $object;
    }

    /**
     * Los datos del objeto en este momento (toArray()). Impuro para PHPStan, que si no recordaría
     * el resultado de la llamada anterior aunque el objeto haya cambiado en el medio.
     *
     * @phpstan-impure
     * @return array<array-key, mixed>
     */
    private static function fieldsOf(RayaObject $object): array
    {
        return $object->toArray();
    }

    /** json.loads(json.dumps(obj)) en Python: el JSON de json_encode(), con arrays comunes. */
    private static function jsonRoundTrip(\JsonSerializable $object): mixed
    {
        $json = json_encode($object, JSON_THROW_ON_ERROR);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Las claves ordenadas, en todos los niveles (las listas quedan como están), como
     * sort_keys=True en Python.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortedKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(
            static fn(mixed $item): mixed => is_array($item) ? self::sortedKeys($item) : $item,
            $value,
        );
    }
}
