<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\PspCustomerService;

/**
 * Un cliente de tu empresa en Raya PSP: una persona humana (F) o jurídica (J) con un CVU a su
 * nombre, en el que recibe transferencias y desde el que transferís en su nombre.
 *
 * Lo creás desde tu servidor, con tu clave secreta: si se verificó con Raya Identity, con el id de
 * su verificación; si no, con su CUIL o CUIT, su nombre y, si es una persona humana, las fotos de
 * su DNI y una selfie. Te avisamos cada cambio con los webhooks psp.customer.*, que traen este
 * mismo objeto. status, blocked_by y los códigos de decline_reason pueden sumar valores: tu
 * código tiene que ignorarlos.
 *
 * @phpstan-import-type PspCustomerCreateParams from PspCustomerService
 * @property-read string $id pspc_ y 24 letras o números.
 * @property-read 'psp.customer' $object
 * @property-read (
 *     'verifying'|'pending'|'active'|'blocked'|'closed'|'failed'|'rejected'
 * ) $status verifying: validamos su identidad o lo estamos revisando. pending: estamos creando
 *     su CVU o asignándole el alias. active: su CVU está activo: recibe y envía. blocked: sigue
 *     recibiendo, pero no puede enviar ni recrear su CVU (blocked_by dice quién lo bloqueó).
 *     closed: su CVU está dado de baja; conserva su saldo y su historial, y se puede recrear.
 *     failed: no pudimos crear su CVU; se puede recrear. rejected: no pudimos validarlo o
 *     aprobarlo (decline_reason dice por qué); no cambia más y libera el CUIL o CUIT.
 * @property-read 'F'|'J' $person_type F: persona humana. J: persona jurídica.
 * @property-read string $tax_id Su CUIL o CUIT: 11 dígitos, sin guiones. Si lo creaste con
 *     identity_verification, el CUIL de la verificación.
 * @property-read string $name Su nombre y apellido (o la razón social): si lo creaste con
 *     identity_verification, los de la verificación, como figuran en el RENAPER; si no, los que
 *     mandaste.
 * @property-read int|float $balance Su saldo, en pesos: sube con lo que recibe y baja con lo que
 *     transferís en su nombre. Nunca es negativo.
 * @property-read string $currency Siempre 'ARS'.
 * @property-read PspCustomer\Cvu|null $cvu Su CVU: el activo o, si no tiene uno, el último que
 *     tuvo; null si nunca tuvo uno.
 * @property-read 'account'|'raya'|null $blocked_by Quién lo bloqueó: account (vos) o raya (si lo
 *     bloqueamos los dos, raya). null si no está blocked.
 * @property-read string|null $identity_verification El id de la verificación de Identidad con la
 *     que lo creaste (idv_…); null si lo creaste con las fotos o es una persona jurídica.
 * @property-read PspCustomer\DeclineReason|null $decline_reason Por qué no se pudo: solo con
 *     status rejected o failed.
 * @property-read string|null $external_reference Tu identificador (por ejemplo, el id de tu
 *     cliente).
 * @property-read array<array-key, string> $metadata Tus pares clave-valor ([] si no mandaste).
 *     PHP convierte las claves que parecen enteros en int, y "0", "1"… en una lista: para
 *     mandarla de vuelta, (object) $pspCustomer->metadata.
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-10-24T13:05:10Z'.
 */
final class PspCustomer extends RayaObject
{
    public const OBJECT_NAME = 'psp.customer';

    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    /** Persona humana (CUIL que empieza con 20, 23, 24 o 27). */
    public const PERSON_TYPE_F = 'F';
    /** Persona jurídica (CUIT que empieza con 30, 33 o 34). */
    public const PERSON_TYPE_J = 'J';

    public const BLOCKED_BY_ACCOUNT = 'account';
    public const BLOCKED_BY_RAYA = 'raya';

    public const DECLINE_CODE_IMAGE_UNAVAILABLE = 'image_unavailable';
    public const DECLINE_CODE_IMAGE_INVALID = 'image_invalid';
    public const DECLINE_CODE_DOCUMENT_UNREADABLE = 'document_unreadable';
    public const DECLINE_CODE_DOCUMENT_SIDES_MISMATCH = 'document_sides_mismatch';
    public const DECLINE_CODE_DOCUMENT_MISMATCH = 'document_mismatch';
    public const DECLINE_CODE_IDENTITY_NOT_VERIFIED = 'identity_not_verified';
    public const DECLINE_CODE_NAME_MISMATCH = 'name_mismatch';
    public const DECLINE_CODE_SELFIE_INVALID = 'selfie_invalid';
    public const DECLINE_CODE_FACE_MISMATCH = 'face_mismatch';
    public const DECLINE_CODE_VERIFICATION_UNAVAILABLE = 'verification_unavailable';
    public const DECLINE_CODE_NOT_APPROVED = 'not_approved';
    public const DECLINE_CODE_BANK_REJECTED = 'bank_rejected';
    public const DECLINE_CODE_PSP_DISABLED = 'psp_disabled';

    protected const INNER_CLASSES = [
        'cvu' => PspCustomer\Cvu::class,
        'decline_reason' => PspCustomer\DeclineReason::class,
    ];
    protected const PLAIN_FIELDS = ['metadata'];

    /**
     * Bloquea a este cliente, con el cliente del que vino (o con el estilo global, si lo armaste a
     * mano o lo deserializaste): sigue recibiendo transferencias, pero no puede enviar. Devuelve
     * el cliente actualizado: este objeto no cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function block(#[\SensitiveParameter] array $options = []): PspCustomer
    {
        Params::arguments('$pspCustomer->block', func_num_args(), ['options']);
        $id = $this->idFor('bloquear');
        return (new PspCustomerService($this->resolveRequestor()))->block($id, $options);
    }

    /**
     * Levanta tu bloqueo de este cliente, con el cliente del que vino (o con el estilo global).
     * Devuelve el cliente actualizado: este objeto no cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function unblock(#[\SensitiveParameter] array $options = []): PspCustomer
    {
        Params::arguments('$pspCustomer->unblock', func_num_args(), ['options']);
        $id = $this->idFor('desbloquear');
        return (new PspCustomerService($this->resolveRequestor()))->unblock($id, $options);
    }

    /**
     * Da de baja el CVU de este cliente, con el cliente del que vino (o con el estilo global).
     * Devuelve el cliente actualizado: este objeto no cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function close(#[\SensitiveParameter] array $options = []): PspCustomer
    {
        Params::arguments('$pspCustomer->close', func_num_args(), ['options']);
        $id = $this->idFor('dar de baja su CVU');
        return (new PspCustomerService($this->resolveRequestor()))->close($id, $options);
    }

    /**
     * Le crea un CVU nuevo a este cliente, con el cliente del que vino (o con el estilo global).
     * Devuelve el cliente actualizado: este objeto no cambia.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public function recreate(#[\SensitiveParameter] array $options = []): PspCustomer
    {
        Params::arguments('$pspCustomer->recreate', func_num_args(), ['options']);
        $id = $this->idFor('recrear su CVU');
        return (new PspCustomerService($this->resolveRequestor()))->recreate($id, $options);
    }

    // --- Estilo global (create/retrieve/list/retrieveBalance y las acciones por id); ver
    // PspCustomerService. ---

    /**
     * Crea un cliente con un CVU a su nombre, con tu clave secreta (RayaCash::setApiKey() o
     * api_key): con identity_verification, con las fotos o una persona jurídica. Ver
     * `$client->psp->customers->create()`.
     *
     * @param PspCustomerCreateParams $params
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
    ): PspCustomer {
        Params::arguments('PspCustomer::create', func_num_args(), ['params', 'options']);
        return (new PspCustomerService(GlobalState::requestor()))->create($params, $options);
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('PspCustomer::retrieve', func_num_args(), ['customerId', 'options']);
        return (new PspCustomerService(GlobalState::requestor()))->retrieve(
            $customerId,
            $options,
        );
    }

    /**
     * @param array{
     *     status?: 'verifying'|'pending'|'active'|'blocked'|'closed'|'failed'|'rejected'|null,
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
     * @return ListObject<PspCustomer>
     */
    public static function list(
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): ListObject {
        Params::arguments('PspCustomer::list', func_num_args(), ['params', 'options']);
        return (new PspCustomerService(GlobalState::requestor()))->list($params, $options);
    }

    /**
     * El saldo de un cliente y lo que podés transferir ahora en su nombre. Ver
     * `$client->psp->customers->retrieveBalance()`.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieveBalance(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomerBalance {
        Params::arguments(
            'PspCustomer::retrieveBalance',
            func_num_args(),
            ['customerId', 'options'],
        );
        return (new PspCustomerService(GlobalState::requestor()))->retrieveBalance(
            $customerId,
            $options,
        );
    }

    /**
     * Bloquea a un cliente por id, con el estilo global. Desde un cliente que ya tenés, usá
     * `$pspCustomer->block()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function blockById(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('PspCustomer::blockById', func_num_args(), ['customerId', 'options']);
        return (new PspCustomerService(GlobalState::requestor()))->block($customerId, $options);
    }

    /**
     * Levanta tu bloqueo de un cliente por id, con el estilo global. Desde un cliente que ya
     * tenés, usá `$pspCustomer->unblock()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function unblockById(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('PspCustomer::unblockById', func_num_args(), ['customerId', 'options']);
        return (new PspCustomerService(GlobalState::requestor()))->unblock($customerId, $options);
    }

    /**
     * Da de baja el CVU de un cliente por id, con el estilo global. Desde un cliente que ya tenés,
     * usá `$pspCustomer->close()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function closeById(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('PspCustomer::closeById', func_num_args(), ['customerId', 'options']);
        return (new PspCustomerService(GlobalState::requestor()))->close($customerId, $options);
    }

    /**
     * Le crea un CVU nuevo a un cliente por id, con el estilo global. Desde un cliente que ya
     * tenés, usá `$pspCustomer->recreate()`.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function recreateById(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments(
            'PspCustomer::recreateById',
            func_num_args(),
            ['customerId', 'options'],
        );
        return (new PspCustomerService(GlobalState::requestor()))->recreate(
            $customerId,
            $options,
        );
    }

    /** El id de este cliente, para una acción: sin id, no hay a quién. */
    private function idFor(string $action): string
    {
        $id = $this->rawValue('id');
        if (!is_string($id) || $id === '') {
            throw new \ValueError("Este PspCustomer no tiene id: no se puede {$action}.");
        }
        return $id;
    }
}
