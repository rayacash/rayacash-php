<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\ConnectOrder;
use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;

/**
 * `$client->connect->orders`: los pagos que tu aplicación crea a nombre de una cuenta conectada,
 * con tu comisión fija.
 *
 * Con un RayaClient armado con el access_token de esa cuenta (raya_at_live_…), no con tu clave.
 *
 * En la 1.1.0 era `$client->checkout->orders` (CheckoutOrderService): ese nombre sigue andando
 * como alias deprecado de este mismo objeto hasta la 2.0.0.
 *
 * @final
 */
class ConnectOrderService
{
    // En el orden del body de la API.
    private const CREATE_PARAMS = [
        'items',
        'fee',
        'description',
        'external_reference',
        'metadata',
        'back_urls',
        'auto_return',
        'notification_url',
        'expires_in',
    ];
    private const ITEM_PARAMS = ['title', 'quantity', 'unit_price'];
    private const BACK_URL_PARAMS = ['success', 'failure'];
    private const ITEM_EXAMPLE
        = "['title' => 'Pedido 1042', 'quantity' => 1, 'unit_price' => 45000]";

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Crea un pago pending a nombre de la cuenta conectada del access_token, con un CVU y un
     * alias exclusivos (POST /v1/connect/orders).
     *
     * Mandá a quien paga a checkout_url (nuestra página, con el nombre y la imagen del comercio
     * conectado), o mostrale bank_transfer->cvu y bank_transfer->alias. Te avisamos con
     * connect.order.succeeded cuando llega la transferencia: lo recibido menos fee se liquida al
     * comercio conectado y fee, a tu cuenta (y el comercio recibe su payment.succeeded). Puede
     * tardar hasta 40 s si damos de alta un CVU nuevo (el timeout por defecto es de 65 s).
     *
     * La idempotency_key es opcional pero recomendada: guardala con el pedido y reusala si
     * reintentás. Si no la pasás, generamos una que protege los reintentos de esta llamada.
     *
     * @param array{
     *     items: list<array{
     *         title: string,
     *         quantity: int,
     *         unit_price: int|float|string|\Stringable,
     *     }>,
     *     fee?: int|float|string|\Stringable|null,
     *     description?: string|null,
     *     external_reference?: string|null,
     *     metadata?: array<string, string>|\stdClass|null,
     *     back_urls?: array{success?: string|null, failure?: string|null}|null,
     *     auto_return?: 'approved'|null,
     *     notification_url?: string|null,
     *     expires_in?: int|null,
     * } $params items, de 1 a 20 (title hasta 255 caracteres, quantity de 1 a 999 y unit_price en
     *     pesos con hasta 2 decimales): el total, la suma de quantity × unit_price, es lo que
     *     tiene que transferir quien paga. fee, tu comisión en pesos (un monto, no un
     *     porcentaje), de 0 a menos que el total; por defecto 0. description, hasta 255
     *     caracteres; sin ella queda el title del ítem (con varios, «<title> y N más»).
     *     external_reference, tu identificador (el número de pedido). metadata, hasta 20 pares
     *     de texto. back_urls, a dónde vuelve quien paga desde checkout_url (success y failure).
     *     auto_return, 'approved' para que vuelva solo a back_urls success al acreditarse.
     *     notification_url, el id (we_…) o la URL de un destino tuyo de Developers › Webhooks
     *     para los connect.order.* de este pago; sin él, los destinos suscriptos. expires_in,
     *     cuántos segundos se puede pagar, de 300 a 864000 (10 días); por defecto, el plazo del
     *     comercio conectado (24 h salvo que lo haya cambiado). Cada pago pending ocupa un CVU
     *     del comercio: no lo alargues más de lo necesario.
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(array $params, #[\SensitiveParameter] array $options = []): ConnectOrder
    {
        Params::arguments('connect->orders->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'connect->orders->create',
            $params,
            self::CREATE_PARAMS,
            "['items' => [" . self::ITEM_EXAMPLE . '], \'fee\' => 500]',
        );
        $fee = $values['fee'] ?? null;
        $body = [
            'items' => self::items($values['items'] ?? null),
            'fee' => $fee === null ? null : Encoding::canonicalAmount($fee, 'fee'),
            'description' => $values['description'] ?? null,
            'external_reference' => $values['external_reference'] ?? null,
            'metadata' => Params::metadata($values['metadata'] ?? null),
            'back_urls' => self::backUrls($values['back_urls'] ?? null),
            'auto_return' => $values['auto_return'] ?? null,
            'notification_url' => $values['notification_url'] ?? null,
            'expires_in' => $values['expires_in'] ?? null,
        ];
        return $this->requestor->requestAs(
            ConnectOrder::class,
            'POST',
            '/v1/connect/orders',
            body: $body,
            options: RequestOptions::check($options),
        );
    }

    /**
     * Un pago de tu aplicación a nombre de esta cuenta (GET /v1/connect/orders/:id). Los de otras
     * cuentas conectadas dan NotFoundException con getErrorCode() === 'ORDER_NOT_FOUND'.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(
        string $orderId,
        #[\SensitiveParameter]
        array $options = [],
    ): ConnectOrder {
        Params::arguments('connect->orders->retrieve', func_num_args(), ['orderId', 'options']);
        return $this->requestor->requestAs(
            ConnectOrder::class,
            'GET',
            self::path($orderId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Devuelve todo lo recibido a la cuenta desde la que se pagó y revierte tu comisión
     * (POST /v1/connect/orders/:id/refund).
     *
     * Solo pagos succeeded, hasta 30 días después de paid_at. Es total: no hay reembolsos
     * parciales. Si la confirmación tarda, sale ApiException con getErrorCode() ===
     * 'BANK_UNAVAILABLE': reintentá más tarde con la misma idempotency_key (nunca se manda dos
     * veces) o esperá el webhook connect.order.refunded.
     *
     * @param array{
     *     idempotency_key?: string|null,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function refund(
        string $orderId,
        #[\SensitiveParameter]
        array $options = [],
    ): ConnectOrder {
        Params::arguments('connect->orders->refund', func_num_args(), ['orderId', 'options']);
        return $this->requestor->requestAs(
            ConnectOrder::class,
            'POST',
            self::path($orderId) . '/refund',
            options: RequestOptions::check($options),
        );
    }

    private static function path(string $orderId): string
    {
        return '/v1/connect/orders/' . Encoding::pathId('orderId', $orderId);
    }

    /**
     * Los ítems, con unit_price como número JSON exacto (igual que amount). Lo demás (cuántos,
     * los rangos, un campo que falta) lo valida la API, que nombra el ítem: items[0].title.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(mixed $items): array
    {
        if (!is_array($items) || ($items !== [] && !array_is_list($items))) {
            throw new \TypeError(
                'items tiene que ser una lista de ítems, como [' . self::ITEM_EXAMPLE . '], no '
                . get_debug_type($items) . '.',
            );
        }
        $result = [];
        foreach ($items as $index => $item) {
            $name = "items[{$index}]";
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new \TypeError(
                    "{$name} tiene que ser un array con title, quantity y unit_price, como "
                    . self::ITEM_EXAMPLE . ', no ' . get_debug_type($item) . '.',
                );
            }
            $values = Params::check($name, $item, self::ITEM_PARAMS, self::ITEM_EXAMPLE);
            $unitPrice = $values['unit_price'] ?? null;
            // Solo lo que vino: un campo que falta lo reclama la API (PARAMETER_MISSING).
            $result[] = array_filter(
                [
                    'title' => $values['title'] ?? null,
                    'quantity' => $values['quantity'] ?? null,
                    'unit_price' => $unitPrice === null
                        ? null
                        : Encoding::canonicalAmount($unitPrice, "{$name}.unit_price"),
                ],
                static fn(mixed $value): bool => $value !== null,
            );
        }
        return $result;
    }

    /**
     * Las URLs de vuelta que vinieron; null si no vino ninguna (no va back_urls).
     *
     * @return array<string, mixed>|null
     */
    private static function backUrls(mixed $backUrls): ?array
    {
        if ($backUrls === null) {
            return null;
        }
        if (!is_array($backUrls)) {
            throw new \TypeError(
                "back_urls tiene que ser un array con success y/o failure, como ['success' => "
                . "'https://tienda.example.com/pedidos/1042'], no " . get_debug_type($backUrls)
                . '.',
            );
        }
        $values = Params::check(
            'back_urls',
            $backUrls,
            self::BACK_URL_PARAMS,
            "['success' => 'https://tienda.example.com/pedidos/1042']",
        );
        $result = [];
        foreach (self::BACK_URL_PARAMS as $name) {
            if (($values[$name] ?? null) !== null) {
                $result[$name] = $values[$name];
            }
        }
        return $result === [] ? null : $result;
    }
}

// CheckoutOrderService, el nombre de la 1.1.0, es un alias deprecado de esta clase hasta la
// 2.0.0 (ver Internal\Deprecation).
\class_alias(ConnectOrderService::class, CheckoutOrderService::class);
