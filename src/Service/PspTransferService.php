<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\ListObject;
use RayaCash\PspTransfer;

/**
 * `$client->psp->transfers`: transferir en nombre de tus clientes de Raya PSP y consultar lo que
 * recibieron y enviaron.
 *
 * Con tu clave secreta (raya_sk_live_…). Estos movimientos no aparecen en `$client->transfers`
 * ni emiten transfer.*: te avisamos con los webhooks psp.transfer.*.
 *
 * @final
 */
class PspTransferService
{
    private const PATH = '/v1/psp/transfers';

    // El espejo de la API (pspexternal/api_params.py): en este orden viaja el body…
    private const CREATE_PARAMS = ['customer', 'amount', 'destination'];
    // …y en este, la query.
    private const LIST_PARAMS = [
        'customer',
        'direction',
        'status',
        'created_gte',
        'created_lt',
        'limit',
        'cursor',
    ];

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Transfiere pesos en nombre de un cliente, desde su CVU, a un CBU, CVU o alias
     * (POST /v1/psp/transfers).
     *
     * Tienen que alcanzar los dos saldos: primero el tuyo (si no, UnprocessableEntityException
     * con getErrorCode() === 'INSUFFICIENT_BALANCE', aunque el cliente tenga saldo) y después el
     * del cliente ('CUSTOMER_INSUFFICIENT_BALANCE'). Su saldo baja en amount y la comisión se
     * descuenta del tuyo (fee; 0 entre clientes de tu cuenta). Buscamos al titular del destino y
     * te lo devolvemos en counterparty: no hay paso de confirmación, revisá el destino antes de
     * llamar. Si el destino es una cuenta de Raya, vuelve completed; si no, pending hasta que te
     * avisamos con psp.transfer.sent o psp.transfer.failed. El cliente tiene que estar active: si
     * no, ConflictException con getErrorCode() === 'CUSTOMER_STATUS_CONFLICT' y su status y su
     * blocked_by en getData(); uno que no existe o no es tuyo da NotFoundException con
     * 'CUSTOMER_NOT_FOUND'.
     *
     * La idempotency_key es obligatoria: un UUID v4 que guardás con la operación ANTES de llamar
     * y reusás en cada reintento. Así nunca se transfiere dos veces. Para volver a intentar una
     * transferencia que terminó failed, usá una clave nueva.
     *
     * @param array{
     *     customer: string,
     *     amount: int|float|string|\Stringable,
     *     destination: string,
     * } $params customer, el id del cliente (pspc_…); amount en pesos, hasta 2 decimales
     *     ('18350.50' o un BcMath\Number), lo que baja su saldo; destination, un CBU o CVU (22
     *     dígitos) o un alias (6 a 20 caracteres), siempre como string.
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(array $params, #[\SensitiveParameter] array $options): PspTransfer
    {
        Params::arguments('psp->transfers->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'psp->transfers->create',
            $params,
            self::CREATE_PARAMS,
            "['customer' => 'pspc_…', 'amount' => 18350.5, 'destination' => 'proveedor.ejemplo']",
        );
        $customer = $values['customer'] ?? null;
        if (!is_string($customer)) {
            throw new \TypeError('customer tiene que ser un string: el id del cliente (pspc_…).');
        }
        $destination = $values['destination'] ?? null;
        if (!is_string($destination)) {
            throw new \TypeError(
                'destination tiene que ser un string (CBU, CVU o alias): un CBU como número pierde '
                . 'los ceros de adelante.',
            );
        }
        $body = [
            'customer' => $customer,
            'amount' => Encoding::canonicalAmount($values['amount'] ?? null),
            'destination' => $destination,
        ];
        $opts = RequestOptions::check($options);
        $key = $opts->get('idempotency_key');
        if ($key === null) {
            throw new \TypeError(
                'psp->transfers->create necesita idempotency_key: generá un UUID v4 (por ejemplo, '
                . 'con RayaCash\Util\Uuid::v4()), guardalo con la operación antes de llamar y '
                . 'reusalo en cada reintento: $client->psp->transfers->create([...], '
                . "['idempotency_key' => \$clave]).",
            );
        }
        Encoding::idempotencyKey($key);
        return $this->requestor->requestAs(
            PspTransfer::class,
            'POST',
            self::PATH,
            body: $body,
            options: $opts,
        );
    }

    /**
     * Una transferencia de un cliente tuyo, recibida o enviada (GET /v1/psp/transfers/:id). Una
     * que no existe o no es tuya da NotFoundException con getErrorCode() === 'TRANSFER_NOT_FOUND'.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(
        string $transferId,
        #[\SensitiveParameter]
        array $options = [],
    ): PspTransfer {
        Params::arguments('psp->transfers->retrieve', func_num_args(), ['transferId', 'options']);
        return $this->requestor->requestAs(
            PspTransfer::class,
            'GET',
            self::PATH . '/' . Encoding::pathId('transferId', $transferId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Las transferencias de tus clientes, de la más nueva a la más vieja (GET /v1/psp/transfers).
     *
     * Devuelve una página. Para recorrer todas: `foreach ($page->autoPagingIter() as $t)`.
     * created_gte (incluido) y created_lt (excluido) arman un rango semiabierto: los días o meses
     * seguidos no se pisan. Las fechas van como DateTimeInterface o string ISO 8601 con zona.
     *
     * @param array{
     *     customer?: string|null,
     *     direction?: 'inbound'|'outbound'|null,
     *     status?: 'pending'|'completed'|'failed'|'reversed'|null,
     *     created_gte?: string|\DateTimeInterface|null,
     *     created_lt?: string|\DateTimeInterface|null,
     *     limit?: int|null,
     *     cursor?: string|null,
     * } $params customer, las de un cliente (pspc_…; uno que no es tuyo da una lista vacía); limit
     *     va de 1 a 100 (por defecto, 20); cursor es el next_cursor de la página anterior.
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @return ListObject<PspTransfer>
     * @throws \RayaCash\Exception\RayaException
     */
    public function list(array $params = [], #[\SensitiveParameter] array $options = []): ListObject
    {
        Params::arguments('psp->transfers->list', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'psp->transfers->list',
            $params,
            self::LIST_PARAMS,
            "['customer' => 'pspc_…']",
        );
        $filters = [];
        foreach (self::LIST_PARAMS as $name) {
            $filters[$name] = $values[$name] ?? null;
        }
        $query = Encoding::query($filters);
        $opts = RequestOptions::check($options);
        /** @var ListObject<PspTransfer> $page */
        $page = $this->requestor->requestList(self::PATH, $query, $opts);
        return $page;
    }
}
