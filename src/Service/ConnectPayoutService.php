<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\ConnectPayout;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\ListObject;

/**
 * `$client->connect->payouts`: enviarles pesos desde tu saldo a los vendedores de tu plataforma.
 *
 * Con tu clave secreta (raya_sk_live_…), no con el access_token de una cuenta conectada: el
 * dinero sale de tu saldo y queda a tu nombre.
 *
 * @final
 */
class ConnectPayoutService
{
    private const CREATE_PARAMS = ['amount', 'destination'];
    private const LIST_PARAMS = ['status', 'created_gte', 'created_lt', 'limit', 'cursor'];

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Envía pesos desde tu saldo a un vendedor de tu plataforma, por CBU, CVU o alias
     * (POST /v1/connect/payouts).
     *
     * Descontamos la comisión de amount y te devolvemos el desglose. Buscamos al titular del
     * destino y te lo devolvemos en counterparty: no hay paso de confirmación, revisá el destino
     * antes de llamar. Si el destino es una cuenta de Raya, vuelve completed; si no, pending hasta
     * que te avisamos con transfer.sent o transfer.failed, con el mismo id. Si falla, te
     * devolvemos amount entero, incluida la comisión.
     *
     * Sale de la cuenta de tu negocio (el origin que te devolvemos): si todavía no tiene una
     * propia, da PermissionDeniedException con getErrorCode() === 'PAYOUT_ORIGIN_UNAVAILABLE'
     * (configurá el descriptor del comercio en Configuración › Negocio). Los cobros se suman a tu
     * saldo cuando se liquidan: un pago succeeded no significa que ya puedas enviar ese dinero
     * (INSUFFICIENT_BALANCE).
     *
     * La idempotency_key es obligatoria: un UUID v4 que guardás con la operación ANTES de llamar
     * y reusás en cada reintento. Así nunca se envía dos veces. Para volver a intentar un payout
     * que terminó failed, usá una clave nueva.
     *
     * @param array{amount: int|float|string|\Stringable, destination: string} $params amount, lo
     *     que sale de tu saldo en pesos, hasta 2 decimales ('60000.50' o un BcMath\Number): la
     *     comisión se descuenta de acá; destination, un CBU o CVU (22 dígitos) o un alias (6 a 20
     *     caracteres), siempre como string.
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(array $params, #[\SensitiveParameter] array $options): ConnectPayout
    {
        Params::arguments('connect->payouts->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'connect->payouts->create',
            $params,
            self::CREATE_PARAMS,
            "['amount' => 60000]",
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
                'connect->payouts->create necesita idempotency_key: generá un UUID v4 (por '
                . 'ejemplo, con RayaCash\Util\Uuid::v4()), guardalo con la operación antes de '
                . 'llamar y reusalo en cada reintento: $client->connect->payouts->create([...], '
                . "['idempotency_key' => \$clave]).",
            );
        }
        Encoding::idempotencyKey($key);
        return $this->requestor->requestAs(
            ConnectPayout::class,
            'POST',
            '/v1/connect/payouts',
            body: $body,
            options: $opts,
        );
    }

    /**
     * Un payout tuyo (GET /v1/connect/payouts/:id). Los de otra cuenta, o el id de una
     * transferencia que no es un payout, dan NotFoundException con getErrorCode() ===
     * 'PAYOUT_NOT_FOUND'.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(
        string $payoutId,
        #[\SensitiveParameter]
        array $options = [],
    ): ConnectPayout {
        Params::arguments('connect->payouts->retrieve', func_num_args(), ['payoutId', 'options']);
        return $this->requestor->requestAs(
            ConnectPayout::class,
            'GET',
            '/v1/connect/payouts/' . Encoding::pathId('payoutId', $payoutId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Tus payouts, del más nuevo al más viejo (GET /v1/connect/payouts).
     *
     * Devuelve una página. Para recorrer todos: `foreach ($page->autoPagingIter() as $p)`.
     * created_gte (incluido) y created_lt (excluido) arman un rango semiabierto: los días o meses
     * seguidos no se pisan. Las fechas van como DateTimeInterface o string ISO 8601 con zona.
     *
     * @param array{
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
     * @return ListObject<ConnectPayout>
     * @throws \RayaCash\Exception\RayaException
     */
    public function list(array $params = [], #[\SensitiveParameter] array $options = []): ListObject
    {
        Params::arguments('connect->payouts->list', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'connect->payouts->list',
            $params,
            self::LIST_PARAMS,
            "['status' => 'completed']",
        );
        $filters = [];
        foreach (self::LIST_PARAMS as $name) {
            $filters[$name] = $values[$name] ?? null;
        }
        $query = Encoding::query($filters);
        $opts = RequestOptions::check($options);
        /** @var ListObject<ConnectPayout> $page */
        $page = $this->requestor->requestList('/v1/connect/payouts', $query, $opts);
        return $page;
    }
}
