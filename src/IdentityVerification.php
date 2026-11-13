<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\IdentityVerificationService;

/**
 * Una verificación de identidad (Identidad). document es la completa: DNI (frente y dorso), prueba
 * de vida y validación de los datos y de la cara contra el Registro Nacional de las Personas
 * (RENAPER). biometric es una prueba de vida comparada con la de una document anterior de la
 * misma persona (reference).
 *
 * La creás desde tu servidor, con tu clave secreta, y le mandás url a la persona o la abrís en tu
 * web con Identity.js (el paquete de npm rayacash-identity, que no usa ninguna clave). Te avisamos
 * el resultado con los webhooks identity.verification.*, que traen este mismo objeto, siempre con
 * person en null. Dala por buena solo cuando status es verified. status, los checks y los códigos
 * de decline_reason pueden sumar valores: tu código tiene que ignorarlos.
 *
 * @property-read string $id idv_ y 24 letras o números. También forma parte de url.
 * @property-read 'identity.verification' $object
 * @property-read 'document'|'biometric' $type
 * @property-read (
 *     'pending'|'in_progress'|'processing'|'verified'|'declined'|'expired'|'canceled'
 * ) $status pending: la persona todavía no empezó. in_progress: tocó «Empezar». processing:
 *     terminó la captura y estamos validando contra el registro. verified: pasó todos los
 *     controles (person trae sus datos). declined: no pudimos verificarla (decline_reason dice por
 *     qué). expired: pasó expires_at sin que la terminara. canceled: la cancelaste.
 * @property-read string $url Nuestra página de la verificación: mandásela a la persona o abrila con
 *     Identity.js. Quien tenga el link puede hacer la verificación: compartilo solo con ella.
 * @property-read string|null $external_reference Tu identificador (por ejemplo, el id de tu
 *     cliente).
 * @property-read array<array-key, string> $metadata Tus pares clave-valor ([] si no mandaste).
 *     PHP convierte las claves que parecen enteros en int, y "0", "1"… en una lista: para
 *     mandarla de vuelta, (object) $verification->metadata.
 * @property-read string|null $return_url A dónde puede volver la persona desde la pantalla final.
 * @property-read string|null $reference En una biometric, el id de la verificación document contra
 *     la que se compara; null en una document.
 * @property-read IdentityVerification\DeclineReason|null $decline_reason Por qué se rechazó; null
 *     si no está declined.
 * @property-read IdentityVerification\Checks $checks Cada control con su resultado.
 * @property-read IdentityVerification\Person|null $person Los datos de la persona según el
 *     registro: solo cuando está verified, y en los eventos siempre null (pedilos con
 *     `$client->identity->verifications->retrieve($id)`). Son datos personales: no los loguees.
 * @property-read int|float|null $amount Lo que se cobra por esta verificación, en pesos: queda fijo
 *     cuando pasa a verified (null en cualquier otro estado). Las tarifas de tu cuenta están en
 *     Identidad › Tarifas.
 * @property-read string $currency Siempre 'ARS'.
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-10-24T18:00:00Z'.
 * @property-read string|null $started_at Cuándo la persona tocó «Empezar» por primera vez.
 * @property-read string|null $submitted_at Cuándo terminó la captura.
 * @property-read string|null $completed_at Cuándo llegó a un estado final (verified, declined,
 *     expired o canceled).
 * @property-read string $expires_at Hasta cuándo la persona puede completarla.
 */
final class IdentityVerification extends RayaObject
{
    public const OBJECT_NAME = 'identity.verification';

    public const TYPE_DOCUMENT = 'document';
    public const TYPE_BIOMETRIC = 'biometric';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';

    public const DECLINE_CODE_FACE_MISMATCH = 'face_mismatch';
    public const DECLINE_CODE_REGISTRY_NOT_FOUND = 'registry_not_found';
    public const DECLINE_CODE_REGISTRY_DATA_MISMATCH = 'registry_data_mismatch';
    public const DECLINE_CODE_REGISTRY_UNAVAILABLE = 'registry_unavailable';
    public const DECLINE_CODE_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    protected const INNER_CLASSES = [
        'decline_reason' => IdentityVerification\DeclineReason::class,
        'checks' => IdentityVerification\Checks::class,
        'person' => IdentityVerification\Person::class,
    ];
    protected const PLAIN_FIELDS = ['metadata'];

    /**
     * Cancela esta verificación (solo pending o in_progress), con el cliente del que vino (o con el
     * estilo global, si la armaste a mano o la deserializaste). Devuelve la verificación
     * actualizada: este objeto no cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function cancel(#[\SensitiveParameter] array $options = []): IdentityVerification
    {
        Params::arguments('$verification->cancel', func_num_args(), ['options']);
        $id = $this->rawValue('id');
        if (!is_string($id) || $id === '') {
            throw new \ValueError('Esta IdentityVerification no tiene id: no se puede cancelar.');
        }
        return (new IdentityVerificationService($this->resolveRequestor()))->cancel($id, $options);
    }

    // --- Estilo global (create/retrieve/list/cancelById/files); ver IdentityVerificationService.

    /**
     * Crea una verificación pending con tu clave secreta (RayaCash::setApiKey() o api_key). Ver
     * `$client->identity->verifications->create()`.
     *
     * @param array{
     *     type: 'document'|'biometric',
     *     external_reference?: string|null,
     *     metadata?: array<string, string>|\stdClass|null,
     *     return_url?: string|null,
     *     expires_in?: int|null,
     *     reference?: string|null,
     * } $params
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function create(
        array $params,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerification {
        Params::arguments('IdentityVerification::create', func_num_args(), ['params', 'options']);
        return (new IdentityVerificationService(GlobalState::requestor()))->create(
            $params,
            $options,
        );
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $verificationId,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerification {
        Params::arguments(
            'IdentityVerification::retrieve',
            func_num_args(),
            ['verificationId', 'options'],
        );
        return (new IdentityVerificationService(GlobalState::requestor()))->retrieve(
            $verificationId,
            $options,
        );
    }

    /**
     * @param array{
     *     status?: (
     *         'pending'|'in_progress'|'processing'|'verified'|'declined'|'expired'|'canceled'
     *         |null
     *     ),
     *     type?: 'document'|'biometric'|null,
     *     external_reference?: string|null,
     *     created_gte?: string|\DateTimeInterface|null,
     *     created_lt?: string|\DateTimeInterface|null,
     *     limit?: int|null,
     *     cursor?: string|null,
     * } $params
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @return ListObject<IdentityVerification>
     */
    public static function list(
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): ListObject {
        Params::arguments('IdentityVerification::list', func_num_args(), ['params', 'options']);
        return (new IdentityVerificationService(GlobalState::requestor()))->list(
            $params,
            $options,
        );
    }

    /**
     * Cancela una verificación por id, con el estilo global. Desde una verificación que ya tenés,
     * usá `$verification->cancel()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function cancelById(
        string $verificationId,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerification {
        Params::arguments(
            'IdentityVerification::cancelById',
            func_num_args(),
            ['verificationId', 'options'],
        );
        return (new IdentityVerificationService(GlobalState::requestor()))->cancel(
            $verificationId,
            $options,
        );
    }

    /**
     * Las imágenes de una verificación, con URLs que vencen a los 5 minutos. Ver
     * `$client->identity->verifications->files()`.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function files(
        string $verificationId,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerificationFiles {
        Params::arguments(
            'IdentityVerification::files',
            func_num_args(),
            ['verificationId', 'options'],
        );
        return (new IdentityVerificationService(GlobalState::requestor()))->files(
            $verificationId,
            $options,
        );
    }
}
