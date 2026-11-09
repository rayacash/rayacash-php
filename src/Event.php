<?php

declare(strict_types=1);

namespace RayaCash;

/**
 * Un evento de webhook. Armalo con `Webhook::constructEvent(…)` o `$client->constructEvent(…)`.
 *
 * `$event->data->object` es el Transfer, el Payment o el Checkout tal como estaba cuando ocurrió
 * el evento. Los checkout.* le llegan al dueño de la app (la cuenta conectada recibe sus
 * payment.*). webhook.test (el botón «Enviar evento de prueba») trae `{"object": "test", …}`:
 * respondé 2xx e ignoralo. Pueden sumarse tipos nuevos: tu código tiene que ignorarlos.
 *
 * @property-read string $id evt_…: el mismo valor que el header Raya-Event-Id. Procesalo una vez.
 * @property-read 'event' $object
 * @property-read (
 *     'transfer.received'|'transfer.sent'|'transfer.failed'|'payment.created'
 *     |'payment.succeeded'|'payment.failed'|'payment.refunded'|'checkout.created'
 *     |'checkout.succeeded'|'checkout.failed'|'checkout.refunded'|'webhook.test'
 * ) $type
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
    public const TYPE_CHECKOUT_CREATED = 'checkout.created';
    public const TYPE_CHECKOUT_SUCCEEDED = 'checkout.succeeded';
    public const TYPE_CHECKOUT_FAILED = 'checkout.failed';
    public const TYPE_CHECKOUT_REFUNDED = 'checkout.refunded';
    public const TYPE_WEBHOOK_TEST = 'webhook.test';

    protected const INNER_CLASSES = ['data' => Event\Data::class];
}
