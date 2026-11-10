<?php

declare(strict_types=1);

namespace RayaCash;

/**
 * Un evento de webhook. Armalo con `Webhook::constructEvent(…)` o `$client->constructEvent(…)`.
 *
 * `$event->data->object` es el Transfer, el Payment o el ConnectOrder tal como estaba cuando
 * ocurrió el evento. Los connect.order.* le llegan al dueño de la app (la cuenta conectada recibe
 * sus payment.*). webhook.test (el botón «Enviar evento de prueba») trae `{"object": "test", …}`:
 * respondé 2xx e ignoralo. Pueden sumarse tipos nuevos: tu código tiene que ignorarlos.
 *
 * @property-read string $id evt_…: el mismo valor que el header Raya-Event-Id. Procesalo una vez.
 * @property-read 'event' $object
 * @property-read (
 *     'transfer.received'|'transfer.sent'|'transfer.failed'|'payment.created'
 *     |'payment.succeeded'|'payment.failed'|'payment.refunded'|'connect.order.created'
 *     |'connect.order.succeeded'|'connect.order.failed'|'connect.order.refunded'
 *     |'webhook.test'|'checkout.created'|'checkout.succeeded'|'checkout.failed'
 *     |'checkout.refunded'
 * ) $type Los checkout.* son los nombres de la 1.1.0 de los connect.order.*: la API ya no los
 *     manda (los trae un evento de antes del cambio, si se reenvía).
 * @property-read string $api_version Versión del formato del evento, por ahora '2026-10-23'.
 * @property-read string $created_at Cuándo ocurrió (ISO 8601 en UTC).
 * @property-read Event\Data $data
 */
final class Event extends RayaObject
{
    public const OBJECT_NAME = 'event';

    public const TYPE_TRANSFER_RECEIVED = 'transfer.received';
    public const TYPE_TRANSFER_SENT = 'transfer.sent';
    public const TYPE_TRANSFER_FAILED = 'transfer.failed';
    public const TYPE_PAYMENT_CREATED = 'payment.created';
    public const TYPE_PAYMENT_SUCCEEDED = 'payment.succeeded';
    public const TYPE_PAYMENT_FAILED = 'payment.failed';
    public const TYPE_PAYMENT_REFUNDED = 'payment.refunded';
    public const TYPE_CONNECT_ORDER_CREATED = 'connect.order.created';
    public const TYPE_CONNECT_ORDER_SUCCEEDED = 'connect.order.succeeded';
    public const TYPE_CONNECT_ORDER_FAILED = 'connect.order.failed';
    public const TYPE_CONNECT_ORDER_REFUNDED = 'connect.order.refunded';
    public const TYPE_WEBHOOK_TEST = 'webhook.test';

    // Los nombres de la 1.1.0, con los valores nuevos: el código que compara $event->type con
    // ellos sigue reconociendo los eventos de Raya Connect.

    /** @deprecated Desde la 1.2.0: usá TYPE_CONNECT_ORDER_CREATED. Se elimina en la 2.0.0. */
    public const TYPE_CHECKOUT_CREATED = self::TYPE_CONNECT_ORDER_CREATED;
    /** @deprecated Desde la 1.2.0: usá TYPE_CONNECT_ORDER_SUCCEEDED. Se elimina en la 2.0.0. */
    public const TYPE_CHECKOUT_SUCCEEDED = self::TYPE_CONNECT_ORDER_SUCCEEDED;
    /** @deprecated Desde la 1.2.0: usá TYPE_CONNECT_ORDER_FAILED. Se elimina en la 2.0.0. */
    public const TYPE_CHECKOUT_FAILED = self::TYPE_CONNECT_ORDER_FAILED;
    /** @deprecated Desde la 1.2.0: usá TYPE_CONNECT_ORDER_REFUNDED. Se elimina en la 2.0.0. */
    public const TYPE_CHECKOUT_REFUNDED = self::TYPE_CONNECT_ORDER_REFUNDED;

    protected const INNER_CLASSES = ['data' => Event\Data::class];
}
