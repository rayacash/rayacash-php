<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Hydrator;

/**
 * Los webhooks: verificar la firma y armar el Event.
 *
 * ```php
 * $event = Webhook::constructEvent(
 *     file_get_contents('php://input'),          // el body CRUDO, sin parsear
 *     $_SERVER['HTTP_RAYA_SIGNATURE'] ?? null,
 *     $secreto,                                 // whsec_…, de Developers › Webhooks
 * );
 * ```
 */
final class Webhook
{
    /** Diferencia máxima (en segundos) entre t y tu reloj. */
    public const DEFAULT_TOLERANCE = 300;

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * Verifica la firma y devuelve el Event.
     *
     * Tira SignatureVerificationException si la firma no es válida: respondé 400 y no lo
     * proceses. El mismo evento puede llegar más de una vez y desordenado: guardá los ids que ya
     * procesaste. `$event->data->object->refund()` usa el estilo global; con
     * `$client->constructEvent()`, tu cliente.
     *
     * @param string|\Stringable $payload El body crudo, sin parsear (un stream PSR-7 sirve tal
     *     cual).
     * @param string|null $sigHeader El header Raya-Signature.
     * @param int|float|null $tolerance Segundos aceptados entre t y tu reloj; null o 0 lo apagan.
     * @throws Exception\SignatureVerificationException
     */
    public static function constructEvent(
        mixed $payload,
        mixed $sigHeader,
        #[\SensitiveParameter]
        ?string $secret,
        mixed $tolerance = self::DEFAULT_TOLERANCE,
    ): Event {
        $raw = Encoding::webhookPayload($payload);
        WebhookSignature::verifyHeader($raw, $sigHeader, $secret, $tolerance);
        return Hydrator::event($raw, null);
    }
}
