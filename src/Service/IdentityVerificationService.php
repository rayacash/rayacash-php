<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\IdentityVerification;
use RayaCash\IdentityVerificationFiles;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\ListObject;

/**
 * `$client->identity->verifications`: verificar la identidad de tus clientes (DNI, prueba de vida
 * y validación contra el Registro Nacional de las Personas, RENAPER) y leer el resultado.
 *
 * Con tu clave secreta (raya_sk_live_…), siempre desde tu servidor: en el navegador va Identity.js
 * (el paquete de npm rayacash-identity), que no usa ninguna clave, solo el id de la verificación.
 * Identidad todavía no está disponible en el sandbox: con una clave de prueba (raya_sk_test_…) da
 * PermissionDeniedException (403) con getErrorCode() === 'SANDBOX_NOT_SUPPORTED'.
 *
 * @final
 */
class IdentityVerificationService
{
    private const PATH = '/v1/identity/verifications';

    // El espejo de la API (identity/api_params.py): en este orden viaja el body…
    private const CREATE_PARAMS = [
        'type',
        'external_reference',
        'metadata',
        'return_url',
        'expires_in',
        'reference',
    ];
    // …y en este, la query.
    private const LIST_PARAMS = [
        'status',
        'type',
        'external_reference',
        'created_gte',
        'created_lt',
        'limit',
        'cursor',
    ];

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Crea una verificación pending (POST /v1/identity/verifications).
     *
     * Mandale url a la persona, o abrila en tu web con Identity.js; te avisamos el resultado con
     * los webhooks identity.verification.*. Crearla no tiene costo: se cobra solo si termina
     * verified. Cada cuenta tiene un tope de verificaciones abiertas y otro de creaciones por día:
     * al llegar, RateLimitException con getRetryAfter() y getData()['limit'] ('open' o 'daily').
     *
     * La idempotency_key es opcional pero recomendada: guardala con tu registro y reusala si
     * reintentás. Si no la pasás, generamos una que protege los reintentos de esta llamada.
     *
     * @param array{
     *     type: 'document'|'biometric',
     *     external_reference?: string|null,
     *     metadata?: array<string, string>|\stdClass|null,
     *     return_url?: string|null,
     *     expires_in?: int|null,
     *     reference?: string|null,
     * } $params type, document (DNI, prueba de vida y registro) o biometric (prueba de vida contra
     *     una document anterior). external_reference, tu identificador: hasta 128 caracteres, en
     *     una línea, sin datos personales (viaja en la URL al filtrar la lista). metadata, hasta 20
     *     pares de texto. return_url, la URL https:// a la que vuelve la persona desde la pantalla
     *     final. expires_in, los segundos que tiene para completarla, de 3600 (1 hora) a 2592000
     *     (30 días); por defecto, 7 días. reference, solo con biometric (y ahí obligatorio): el id
     *     de una verificación document de tu cuenta ya verified, con checks->registry->face_match
     *     en hit.
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(
        array $params,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerification {
        Params::arguments(
            'identity->verifications->create',
            func_num_args(),
            ['params', 'options'],
        );
        $values = Params::check(
            'identity->verifications->create',
            $params,
            self::CREATE_PARAMS,
            "['type' => 'document']",
        );
        $body = [
            'type' => $values['type'] ?? null,
            'external_reference' => $values['external_reference'] ?? null,
            // Siempre como objeto JSON: [] vacío sería una lista, y la API la rechaza.
            'metadata' => Params::metadata($values['metadata'] ?? null),
            'return_url' => $values['return_url'] ?? null,
            'expires_in' => $values['expires_in'] ?? null,
            'reference' => $values['reference'] ?? null,
        ];
        return $this->requestor->requestAs(
            IdentityVerification::class,
            'POST',
            self::PATH,
            body: $body,
            options: RequestOptions::check($options),
        );
    }

    /**
     * Una verificación de tu cuenta, creada con la API o desde el dashboard
     * (GET /v1/identity/verifications/:id). Si está verified, trae los datos de la persona en
     * person. Una que no existe o no es tuya da NotFoundException con getErrorCode() ===
     * 'VERIFICATION_NOT_FOUND'.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(
        string $verificationId,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerification {
        Params::arguments(
            'identity->verifications->retrieve',
            func_num_args(),
            ['verificationId', 'options'],
        );
        return $this->requestor->requestAs(
            IdentityVerification::class,
            'GET',
            self::path($verificationId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Tus verificaciones, de la más nueva a la más vieja (GET /v1/identity/verifications), cada
     * una completa (con person si está verified).
     *
     * Devuelve una página. Para recorrer todas: `foreach ($page->autoPagingIter() as $v)`.
     * created_gte (incluido) y created_lt (excluido) arman un rango semiabierto: los días o meses
     * seguidos no se pisan. Las fechas van como DateTimeInterface o string ISO 8601 con zona.
     *
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
     * } $params external_reference, coincidencia exacta; limit va de 1 a 100 (por defecto, 20);
     *     cursor es el next_cursor de la página anterior.
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @return ListObject<IdentityVerification>
     * @throws \RayaCash\Exception\RayaException
     */
    public function list(array $params = [], #[\SensitiveParameter] array $options = []): ListObject
    {
        Params::arguments('identity->verifications->list', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'identity->verifications->list',
            $params,
            self::LIST_PARAMS,
            "['status' => 'verified']",
        );
        $filters = [];
        foreach (self::LIST_PARAMS as $name) {
            $filters[$name] = $values[$name] ?? null;
        }
        $query = Encoding::query($filters);
        $opts = RequestOptions::check($options);
        /** @var ListObject<IdentityVerification> $page */
        $page = $this->requestor->requestList(self::PATH, $query, $opts);
        return $page;
    }

    /**
     * Cancela una verificación que la persona todavía no terminó
     * (POST /v1/identity/verifications/:id/cancel, sin body): el link deja de funcionar.
     *
     * Solo pending o in_progress: si no, ConflictException con getErrorCode() ===
     * 'VERIFICATION_NOT_CANCELABLE' y el estado en getData()['status']. Cancelar una que ya está
     * canceled la devuelve igual.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function cancel(
        string $verificationId,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerification {
        Params::arguments(
            'identity->verifications->cancel',
            func_num_args(),
            ['verificationId', 'options'],
        );
        return $this->requestor->requestAs(
            IdentityVerification::class,
            'POST',
            self::path($verificationId) . '/cancel',
            options: RequestOptions::check($options),
        );
    }

    /**
     * Las imágenes de una verificación (GET /v1/identity/verifications/:id/files): front y back
     * (el DNI) y selfie, cada una con una URL firmada que vence a los 5 minutos. Bajalas enseguida
     * con tu propio cliente HTTP y no guardes las URLs. Las que esa verificación no tiene vienen
     * en null.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function files(
        string $verificationId,
        #[\SensitiveParameter]
        array $options = [],
    ): IdentityVerificationFiles {
        Params::arguments(
            'identity->verifications->files',
            func_num_args(),
            ['verificationId', 'options'],
        );
        return $this->requestor->requestAs(
            IdentityVerificationFiles::class,
            'GET',
            self::path($verificationId) . '/files',
            options: RequestOptions::check($options),
        );
    }

    private static function path(string $verificationId): string
    {
        return self::PATH . '/' . Encoding::pathId('verificationId', $verificationId);
    }
}
