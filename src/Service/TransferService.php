<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\ListObject;
use RayaCash\Transfer;

/**
 * `$client->transfers`: enviar pesos y consultar las transferencias enviadas y recibidas.
 *
 * @final
 */
class TransferService
{
    private const CREATE_PARAMS = ['amount', 'destination'];
    private const LIST_PARAMS = [
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
     * Envía pesos desde tu saldo a un CBU, CVU o alias (POST /v1/transfers).
     *
     * Buscamos al titular del destino y te lo devolvemos en counterparty. Si el destino es una
     * cuenta de Raya, vuelve completed; si no, pending hasta que te avisamos con transfer.sent o
     * transfer.failed.
     *
     * La idempotency_key es obligatoria: un UUID v4 que guardás con la operación ANTES de llamar
     * y reusás en cada reintento. Así nunca se transfiere dos veces. Para volver a intentar una
     * transferencia que terminó failed, usá una clave nueva.
     *
     * @param array{amount: int|float|string|\Stringable, destination: string} $params amount en
     *     pesos, hasta 2 decimales ('25000.50' o un BcMath\Number); destination, un CBU o CVU
     *     (22 dígitos) o un alias (6 a 20 caracteres), siempre como string.
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(array $params, #[\SensitiveParameter] array $options): Transfer
    {
        Params::arguments('transfers->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'transfers->create',
            $params,
            self::CREATE_PARAMS,
            "['amount' => 25000]",
        );
        $destination = $values['destination'] ?? null;
        if (!is_string($destination)) {
            throw new \TypeError(
                'destination tiene que ser un string (CBU, CVU o alias): un CBU como número pierde '
                . 'los ceros de adelante.',
            );
        }
        $body = [
            'amount' => Encoding::canonicalAmount($values['amount'] ?? null),
            'destination' => $destination,
        ];
        $opts = RequestOptions::check($options);
        $key = $opts->get('idempotency_key');
        if ($key === null) {
            throw new \TypeError(
                'transfers->create necesita idempotency_key: generá un UUID v4 (por ejemplo, con '
                . 'RayaCash\Util\Uuid::v4()), guardalo con la operación antes de llamar y reusalo '
                . "en cada reintento: \$client->transfers->create([...], ['idempotency_key' => "
                . '$clave]).',
            );
        }
        Encoding::idempotencyKey($key);
        return $this->requestor->requestAs(
            Transfer::class,
            'POST',
            '/v1/transfers',
            body: $body,
            options: $opts,
        );
    }

    /**
     * Una transferencia, enviada o recibida (GET /v1/transfers/:id).
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
    ): Transfer {
        Params::arguments('transfers->retrieve', func_num_args(), ['transferId', 'options']);
        return $this->requestor->requestAs(
            Transfer::class,
            'GET',
            '/v1/transfers/' . Encoding::pathId('transferId', $transferId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Tus transferencias, de la más nueva a la más vieja (GET /v1/transfers).
     *
     * Devuelve una página. Para recorrer todas: `foreach ($page->autoPagingIter() as $t)`.
     * created_gte (incluido) y created_lt (excluido) arman un rango semiabierto: los días o meses
     * seguidos no se pisan. Las fechas van como DateTimeInterface o string ISO 8601 con zona.
     *
     * @param array{
     *     direction?: 'inbound'|'outbound'|null,
     *     status?: 'pending'|'completed'|'failed'|'reversed'|null,
     *     created_gte?: string|\DateTimeInterface|null,
     *     created_lt?: string|\DateTimeInterface|null,
     *     limit?: int|null,
     *     cursor?: string|null,
     * } $params limit va de 1 a 100 (por defecto, 20); cursor es el next_cursor de la página
     *     anterior.
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @return ListObject<Transfer>
     * @throws \RayaCash\Exception\RayaException
     */
    public function list(array $params = [], #[\SensitiveParameter] array $options = []): ListObject
    {
        Params::arguments('transfers->list', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'transfers->list',
            $params,
            self::LIST_PARAMS,
            "['direction' => 'inbound']",
        );
        $filters = [];
        foreach (self::LIST_PARAMS as $name) {
            $filters[$name] = $values[$name] ?? null;
        }
        $query = Encoding::query($filters);
        $opts = RequestOptions::check($options);
        /** @var ListObject<Transfer> $page */
        $page = $this->requestor->requestList('/v1/transfers', $query, $opts);
        return $page;
    }
}
