<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Text;
use RayaCash\Internal\Timing;

/**
 * La firma de los webhooks (header Raya-Signature).
 *
 * `Raya-Signature: t=<segundos unix>,v1=<firma>[,v1=<firma>]`, con la firma
 * HMAC-SHA256(secreto, "{t}." . body crudo) en hexadecimal. El secreto (whsec_…) se usa entero.
 * Mientras rotás el secreto llegan dos v1: alcanza con que coincida uno. La firma se calcula
 * sobre los bytes tal como llegaron: si tu framework ya parseó el JSON, volver a serializarlo
 * cambia el body y la firma no coincide.
 */
final class WebhookSignature
{
    public const EXPECTED_SCHEME = 'v1';

    private const TIMESTAMP = '/\A[0-9]{1,20}\z/';
    private const SIGNATURE = '/\A[0-9a-fA-F]{64}\z/';

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * La firma v1 (hex) de un body con un t dado: un int, o un string de hasta 20 dígitos.
     *
     * @param string|\Stringable $payload El body crudo.
     */
    public static function computeSignature(
        mixed $payload,
        #[\SensitiveParameter]
        ?string $secret,
        int|string $timestamp,
    ): string {
        $raw = Encoding::webhookPayload($payload);
        $key = self::checkSecret($secret);
        return hash_hmac('sha256', self::checkTimestamp($timestamp) . '.' . $raw, $key);
    }

    /**
     * Devuelve true si la firma es válida; si no, tira SignatureVerificationException.
     *
     * @param string|\Stringable $payload El body crudo, sin parsear.
     * @param string|null $header El valor del header Raya-Signature.
     * @param int|float|null $tolerance Segundos de diferencia aceptados entre t y tu reloj, para
     *     los dos lados. null (o 0) desactiva el chequeo: solo para probar con firmas viejas.
     * @return true
     */
    public static function verifyHeader(
        mixed $payload,
        mixed $header,
        #[\SensitiveParameter]
        ?string $secret,
        mixed $tolerance = Webhook::DEFAULT_TOLERANCE,
    ): bool {
        $raw = Encoding::webhookPayload($payload);
        $key = self::checkSecret($secret);
        $maxSkew = self::checkTolerance($tolerance);

        if ($header !== null && !is_string($header)) {
            throw new \TypeError(
                'header tiene que ser el valor del header Raya-Signature, un string: con PSR-7, '
                . "\$request->getHeaderLine('Raya-Signature'). Llegó " . get_debug_type($header)
                . '.',
            );
        }
        if ($header === null || Text::isBlank($header)) {
            throw self::failure('Falta el header Raya-Signature.', $header, $raw);
        }
        [$timestamp, $signatures] = self::parseHeader($header);
        if ($timestamp === null) {
            throw self::failure(
                'No pudimos leer t (el momento del envío) del header Raya-Signature.',
                $header,
                $raw,
            );
        }
        if ($signatures === []) {
            throw self::failure('El header Raya-Signature no trae firmas v1.', $header, $raw);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $key);
        $matched = false;
        foreach ($signatures as $signature) {
            // Contra cada v1 y en tiempo constante.
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (!$matched) {
            throw self::failure(
                'La firma no coincide: revisá el secreto (whsec_…) y que uses el body crudo, sin '
                . 'parsear.',
                $header,
                $raw,
            );
        }
        if ($maxSkew && abs(Timing::now() - (float) $timestamp) > $maxSkew) {
            throw self::failure(
                "El momento de la firma (t={$timestamp}) está a más de " . self::seconds($maxSkew)
                . ' s de tu reloj. Si es un reintento, se firma con un t nuevo: revisá la hora del '
                . 'servidor.',
                $header,
                $raw,
            );
        }
        return true;
    }

    /**
     * Un header Raya-Signature válido, para tus propios tests. Sin timestamp, usa la hora actual.
     *
     * @param string|\Stringable $payload El body crudo.
     */
    public static function generateHeader(
        mixed $payload,
        #[\SensitiveParameter]
        ?string $secret,
        ?int $timestamp = null,
    ): string {
        if ($timestamp !== null && $timestamp < 0) {
            throw new \ValueError('timestamp tiene que ser un int de segundos unix.');
        }
        $t = $timestamp ?? (int) floor(Timing::now());
        $signature = self::computeSignature($payload, $secret, $t);
        return "t={$t}," . self::EXPECTED_SCHEME . "={$signature}";
    }

    /** El error lleva el header tal como llegó y el body (con U+FFFD en lo que no es UTF-8). */
    private static function failure(
        string $message,
        ?string $header,
        string $raw,
    ): SignatureVerificationException {
        return new SignatureVerificationException($message, $header, Text::utf8($raw));
    }

    private static function checkSecret(#[\SensitiveParameter] ?string $secret): string
    {
        if ($secret === null || Text::isBlank($secret)) {
            throw new \ValueError(
                'Falta el secreto del webhook (whsec_…): está en Developers › Webhooks.',
            );
        }
        return $secret;
    }

    /**
     * mixed y no int|float|null: sin strict_types, PHP convertiría false en 0, que apaga el
     * chequeo del reloj. Un bool o un texto son un TypeError, como en Python y Node.
     */
    private static function checkTolerance(mixed $tolerance): ?float
    {
        if ($tolerance === null) {
            return null;
        }
        if (!is_int($tolerance) && !is_float($tolerance)) {
            throw new \TypeError('tolerance tiene que ser un número de segundos o null.');
        }
        if (is_nan((float) $tolerance) || $tolerance < 0) {
            throw new \ValueError('tolerance tiene que ser un número de segundos, 0 o más.');
        }
        return (float) $tolerance;
    }

    /** El t a firmar, sin ceros adelante (como el int() de Python, pero sin perder dígitos). */
    private static function checkTimestamp(int|string $timestamp): string
    {
        $valid = is_int($timestamp)
            ? $timestamp >= 0
            : \preg_match(self::TIMESTAMP, $timestamp) === 1;
        if (!$valid) {
            throw new \ValueError('timestamp tiene que ser un int de segundos unix.');
        }
        return ltrim((string) $timestamp, '0') ?: '0';
    }

    /**
     * El primer t válido (hasta 20 dígitos ASCII, como string) y las firmas v1, en minúscula.
     * Tolerante: espacios, orden y esquemas desconocidos no importan.
     *
     * @return array{string|null, list<string>}
     */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $part = Text::strip($part) ?? $part;
            $separator = \strpos($part, '=');
            if ($separator === false) {
                continue;
            }
            $key = Text::strip(\substr($part, 0, $separator)) ?? '';
            $value = Text::strip(\substr($part, $separator + 1)) ?? '';
            if ($key === 't' && $timestamp === null && \preg_match(self::TIMESTAMP, $value) === 1) {
                $timestamp = ltrim($value, '0') ?: '0';
            } elseif (
                $key === self::EXPECTED_SCHEME
                && \preg_match(self::SIGNATURE, $value) === 1
            ) {
                $signatures[] = Text::asciiLower($value);
            }
        }
        return [$timestamp, $signatures];
    }

    /**
     * 300, o 300.5: los segundos exactos, sin «.0» ni redondeo. El {:g} de Python los redondea a
     * 6 cifras (1234567 sale 1.23457e+06): con las tolerancias de siempre, como 300 o 3600, da
     * lo mismo.
     */
    private static function seconds(float $value): string
    {
        return $value === floor($value) && abs($value) < 1e15
            ? (string) (int) $value
            : Encoding::floatText($value);
    }
}
