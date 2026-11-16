<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\ListObject;
use RayaCash\PspCustomer;
use RayaCash\PspCustomerBalance;

/**
 * `$client->psp->customers`: los clientes de tu empresa en Raya PSP, cada uno con un CVU a su
 * nombre.
 *
 * Con tu clave secreta (raya_sk_live_…), siempre desde tu servidor. Solo para cuentas de empresa
 * con Raya PSP activado: si no, crear un cliente y las acciones
 * (salvo bloquear) dan PermissionDeniedException (403) con getErrorCode() ===
 * 'PSP_NOT_ACTIVATED'; las lecturas responden igual (sin activación, la lista sale vacía). Raya
 * PSP todavía no está disponible en el sandbox: con una clave de prueba (raya_sk_test_…) da
 * PermissionDeniedException con getErrorCode() === 'SANDBOX_NOT_SUPPORTED'.
 *
 * Un cliente que no existe o no es tuyo (también un id que no es pspc_…) da NotFoundException con
 * getErrorCode() === 'CUSTOMER_NOT_FOUND'. Una acción que su estado no permite da
 * ConflictException con getErrorCode() === 'CUSTOMER_STATUS_CONFLICT', con su status y su
 * blocked_by en getData().
 *
 * PspCustomerCreateParams es el tipo de los parámetros de create, una de sus tres formas: con
 * Raya Identity (solo identity_verification), con las fotos (una persona humana, con tu propio
 * KYC) o una persona jurídica. Lo usa también PspCustomer::create(), y tu código lo puede
 * importar.
 *
 * @phpstan-type PspCustomerCreateParams (
 *     array{
 *         identity_verification: string,
 *         tax_id?: null,
 *         name?: null,
 *         person_type?: null,
 *         document_front_url?: null,
 *         document_back_url?: null,
 *         selfie_url?: null,
 *         external_reference?: string|null,
 *         metadata?: array<string, string>|\stdClass|null,
 *     }
 *     |array{
 *         identity_verification?: null,
 *         tax_id: string,
 *         name: string,
 *         person_type: 'F',
 *         document_front_url: string,
 *         document_back_url: string,
 *         selfie_url: string,
 *         external_reference?: string|null,
 *         metadata?: array<string, string>|\stdClass|null,
 *     }
 *     |array{
 *         identity_verification?: null,
 *         tax_id: string,
 *         name: string,
 *         person_type: 'J',
 *         document_front_url?: null,
 *         document_back_url?: null,
 *         selfie_url?: null,
 *         external_reference?: string|null,
 *         metadata?: array<string, string>|\stdClass|null,
 *     }
 * )
 * @final
 */
class PspCustomerService
{
    private const PATH = '/v1/psp/customers';

    // El espejo de la API (pspexternal/api_params.py): en este orden viaja el body…
    private const CREATE_PARAMS = [
        'identity_verification',
        'tax_id',
        'name',
        'person_type',
        'document_front_url',
        'document_back_url',
        'selfie_url',
        'external_reference',
        'metadata',
    ];
    // …y en este, la query.
    private const LIST_PARAMS = [
        'status',
        'external_reference',
        'created_gte',
        'created_lt',
        'limit',
        'cursor',
    ];
    // Sin identity_verification van los datos: la pista, por si tu cliente se verificó con Raya
    // Identity (entonces alcanza con ella).
    private const IDENTITY_HINT = ' Si tu cliente se verificó con Raya Identity, mandá solo '
        . 'identity_verification.';

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Crea un cliente con un CVU a su nombre (POST /v1/psp/customers), de una de tres formas:
     *
     * - Si se verificó con Raya Identity, solo identity_verification: el id de una verificación
     *   document de tu cuenta, verified, que no usaste para otro cliente. Su CUIL, su nombre y
     *   apellido, su sexo y su nacionalidad salen de la verificación (es una persona humana, F):
     *   si mandás además tax_id, name, person_type o las fotos, InvalidRequestException con
     *   getErrorCode() === 'PARAMETER_INVALID'.
     * - Una persona humana con tu propio KYC (o el de otro proveedor): tax_id, name, person_type
     *   F y las URLs https:// del frente y del dorso de su DNI y de una selfie
     *   (document_front_url, document_back_url y selfie_url: JPEG o PNG, derechas y de hasta
     *   5 MB), que bajamos y validamos contra el Registro Nacional de las Personas (RENAPER). El
     *   sexo lo leemos del frente del DNI.
     * - Una persona jurídica: tax_id (su CUIT), name (su razón social) y person_type J, sin fotos:
     *   validamos su CUIT y su razón social contra el Registro Nacional de Sociedades.
     *
     * Vuelve active (con su CVU y su alias) o failed; o verifying o pending, si seguimos en
     * segundo plano (con las fotos y con una persona jurídica, siempre verifying): ahí te avisamos
     * con los webhooks psp.customer.activated, psp.customer.failed o psp.customer.rejected. Si ya
     * tenés un cliente con ese CUIL o CUIT, o con esa verificación, ConflictException con
     * getErrorCode() === 'CUSTOMER_ALREADY_EXISTS' y su id en getData()['customer']; si la razón
     * social de una persona jurídica no es la del Registro Nacional de Sociedades,
     * UnprocessableEntityException con 'CUSTOMER_DATA_MISMATCH' (getData()['field'] es name), y si
     * su CUIT no está en ese registro, 'TAX_ID_NOT_REGISTERED'.
     *
     * Antes de mandar nada fallan un parámetro que no es de la API, una identity_verification que
     * no es un string y, sin ella, un tax_id o un name que no son strings. Que los parámetros
     * sean una de las tres formas lo valida la API (PARAMETER_MISSING o PARAMETER_INVALID, con
     * el campo en getParam()).
     *
     * La idempotency_key es opcional pero recomendada: guardala con tu cliente y reusala si
     * reintentás. Si no la pasás, generamos una que protege los reintentos de esta llamada.
     *
     * @param PspCustomerCreateParams $params identity_verification, el id de la verificación
     *     (idv_…). tax_id, su CUIL o CUIT (11 dígitos, sin guiones, siempre como string). name, su
     *     nombre y apellido (o la razón social), de 2 a 120 caracteres. person_type, F (persona
     *     humana) o J (persona jurídica). external_reference, tu identificador: hasta 128
     *     caracteres, en una línea, sin datos personales (viaja en la URL al filtrar la lista).
     *     metadata, hasta 20 pares de texto.
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
    ): PspCustomer {
        Params::arguments('psp->customers->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'psp->customers->create',
            $params,
            self::CREATE_PARAMS,
            "['identity_verification' => 'idv_…']",
        );
        $verification = $values['identity_verification'] ?? null;
        if ($verification !== null && !is_string($verification)) {
            // Por ejemplo, la IdentityVerification entera en vez de su id.
            throw new \TypeError(
                'identity_verification tiene que ser un string con el id de una verificación de '
                . 'Identidad (idv_…), no ' . get_debug_type($verification) . '.',
            );
        }
        // Con identity_verification, los datos salen de la verificación: si igual vienen, lo
        // responde la API (PARAMETER_INVALID), como una forma que no es ninguna de las tres.
        if ($verification === null && !is_string($values['tax_id'] ?? null)) {
            throw new \TypeError(
                'tax_id tiene que ser un string: el CUIL o CUIT de tu cliente, 11 dígitos sin '
                . "guiones, como '27123456781'." . self::IDENTITY_HINT,
            );
        }
        if ($verification === null && !is_string($values['name'] ?? null)) {
            throw new \TypeError(
                'name tiene que ser un string: el nombre y apellido de tu cliente (o su razón '
                . 'social).' . self::IDENTITY_HINT,
            );
        }
        $body = [
            'identity_verification' => $verification,
            'tax_id' => $values['tax_id'] ?? null,
            'name' => $values['name'] ?? null,
            'person_type' => $values['person_type'] ?? null,
            'document_front_url' => $values['document_front_url'] ?? null,
            'document_back_url' => $values['document_back_url'] ?? null,
            'selfie_url' => $values['selfie_url'] ?? null,
            'external_reference' => $values['external_reference'] ?? null,
            // Siempre como objeto JSON: [] vacío sería una lista, y la API la rechaza.
            'metadata' => Params::metadata($values['metadata'] ?? null),
        ];
        return $this->requestor->requestAs(
            PspCustomer::class,
            'POST',
            self::PATH,
            body: $body,
            options: RequestOptions::check($options),
        );
    }

    /**
     * Un cliente tuyo (GET /v1/psp/customers/:id), con su saldo en balance y su CVU en cvu.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('psp->customers->retrieve', func_num_args(), ['customerId', 'options']);
        return $this->requestor->requestAs(
            PspCustomer::class,
            'GET',
            self::path($customerId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Tus clientes, del más nuevo al más viejo (GET /v1/psp/customers).
     *
     * Devuelve una página. Para recorrer todos: `foreach ($page->autoPagingIter() as $c)`.
     * created_gte (incluido) y created_lt (excluido) arman un rango semiabierto: los días o meses
     * seguidos no se pisan. Las fechas van como DateTimeInterface o string ISO 8601 con zona. No
     * se filtra por CUIL o CUIT (sería un dato personal en la URL): usá external_reference.
     *
     * @param array{
     *     status?: 'verifying'|'pending'|'active'|'blocked'|'closed'|'failed'|'rejected'|null,
     *     external_reference?: string|null,
     *     created_gte?: string|\DateTimeInterface|null,
     *     created_lt?: string|\DateTimeInterface|null,
     *     limit?: int|null,
     *     cursor?: string|null,
     * } $params status active no trae los bloqueados (son blocked); external_reference,
     *     coincidencia exacta; limit va de 1 a 100 (por defecto, 20); cursor es el next_cursor de
     *     la página anterior.
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @return ListObject<PspCustomer>
     * @throws \RayaCash\Exception\RayaException
     */
    public function list(array $params = [], #[\SensitiveParameter] array $options = []): ListObject
    {
        Params::arguments('psp->customers->list', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'psp->customers->list',
            $params,
            self::LIST_PARAMS,
            "['status' => 'active']",
        );
        $filters = [];
        foreach (self::LIST_PARAMS as $name) {
            $filters[$name] = $values[$name] ?? null;
        }
        $query = Encoding::query($filters);
        $opts = RequestOptions::check($options);
        /** @var ListObject<PspCustomer> $page */
        $page = $this->requestor->requestList(self::PATH, $query, $opts);
        return $page;
    }

    /**
     * El saldo de un cliente (GET /v1/psp/customers/:id/balance): available, su saldo, y
     * transferable, lo que podés transferir ahora en su nombre (su saldo sin pasar tu saldo
     * disponible, y 0 si no está active).
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieveBalance(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomerBalance {
        Params::arguments(
            'psp->customers->retrieveBalance',
            func_num_args(),
            ['customerId', 'options'],
        );
        return $this->requestor->requestAs(
            PspCustomerBalance::class,
            'GET',
            self::path($customerId) . '/balance',
            options: RequestOptions::check($options),
        );
    }

    /**
     * Bloquea a un cliente (POST /v1/psp/customers/:id/block, sin body): sigue recibiendo
     * transferencias, pero no puede enviar ni recrear su CVU. Se puede desde pending, active,
     * closed o failed, y es la única acción que no exige Raya PSP activado. Bloquear uno que ya
     * bloqueaste lo devuelve igual; uno verifying o rejected da CUSTOMER_STATUS_CONFLICT.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function block(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('psp->customers->block', func_num_args(), ['customerId', 'options']);
        return $this->action($customerId, 'block', $options);
    }

    /**
     * Levanta tu bloqueo de un cliente (POST /v1/psp/customers/:id/unblock, sin body).
     * Desbloquear uno que no bloqueaste lo devuelve igual. Un bloqueo de Raya solo lo levantamos
     * nosotros: CUSTOMER_STATUS_CONFLICT, con getData() en ['status' => 'blocked', 'blocked_by'
     * => 'raya'].
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function unblock(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('psp->customers->unblock', func_num_args(), ['customerId', 'options']);
        return $this->action($customerId, 'unblock', $options);
    }

    /**
     * Da de baja el CVU de un cliente (POST /v1/psp/customers/:id/close, sin body): queda closed,
     * con su saldo y su historial, y se lo podés recrear. Solo desde active, también con saldo
     * (si lo bloqueaste, la baja levanta tu bloqueo); dar de baja uno closed lo devuelve igual.
     * Desde verifying, pending, failed, rejected o con un bloqueo de Raya, da
     * CUSTOMER_STATUS_CONFLICT.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function close(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('psp->customers->close', func_num_args(), ['customerId', 'options']);
        return $this->action($customerId, 'close', $options);
    }

    /**
     * Le crea un CVU nuevo a un cliente (POST /v1/psp/customers/:id/recreate, sin body): conserva
     * su id, su saldo y su historial. Solo desde closed o failed, sin bloqueos. Vuelve active o
     * failed, o pending si seguimos en segundo plano (te avisamos con psp.customer.activated o
     * psp.customer.failed); recrear uno pending lo devuelve igual. Desde active, verifying,
     * rejected o blocked, da CUSTOMER_STATUS_CONFLICT.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function recreate(
        string $customerId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspCustomer {
        Params::arguments('psp->customers->recreate', func_num_args(), ['customerId', 'options']);
        return $this->action($customerId, 'recreate', $options);
    }

    /**
     * Las cuatro acciones: un POST sin body, con su Idempotency-Key (la tuya o una por llamada).
     *
     * @param array<array-key, mixed> $options
     */
    private function action(
        string $customerId,
        string $action,
        #[\SensitiveParameter]
        array $options,
    ): PspCustomer {
        return $this->requestor->requestAs(
            PspCustomer::class,
            'POST',
            self::path($customerId) . '/' . $action,
            options: RequestOptions::check($options),
        );
    }

    private static function path(string $customerId): string
    {
        return self::PATH . '/' . Encoding::pathId('customerId', $customerId);
    }
}
