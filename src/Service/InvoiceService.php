<?php

declare(strict_types=1);

namespace RayaCash\Service;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\Params;
use RayaCash\Internal\RequestOptions;
use RayaCash\Internal\Requestor;
use RayaCash\Invoice;
use RayaCash\ListObject;

/**
 * `$client->invoices`: emitir facturas electrónicas de ARCA con el Facturador y consultarlas.
 *
 * Con tu clave secreta (raya_sk_live_…), desde tu servidor. Antes, configurá el Facturador en
 * Negocios › Facturador, en el dashboard: el punto de venta, el certificado y la clave privada de
 * ARCA, tu condición frente al IVA y el concepto. Cada factura es real, ante ARCA: el Facturador no
 * está disponible en el sandbox (con una clave de prueba, raya_sk_test_…, da
 * PermissionDeniedException con getErrorCode() === 'SANDBOX_NOT_SUPPORTED').
 *
 * @final
 */
class InvoiceService
{
    private const PATH = '/v1/invoices';

    // El espejo de la API (facturador/api_params.py): en este orden viaja el body…
    private const CREATE_PARAMS = ['dni', 'amount', 'metadata'];
    // …y en este, la query.
    private const LIST_PARAMS = ['status', 'created_gte', 'created_lt', 'limit', 'cursor'];

    /** @internal Lo arma RayaClient (o el estilo global). */
    public function __construct(private readonly Requestor $requestor) {}

    /**
     * Crea una factura y la emite en segundo plano (POST /v1/invoices): vuelve pending (HTTP 202)
     * y te avisamos el resultado con los webhooks invoice.issued, invoice.rejected o
     * invoice.failed. Para leerla después, retrieve().
     *
     * La emitimos con la configuración de tu Facturador: Factura B si sos Responsable Inscripto
     * (amount incluye el IVA del 21 %) o Factura C si sos monotributista, con tu punto de venta y
     * tu concepto. Si tu Facturador todavía no puede emitir, PermissionDeniedException con
     * getErrorCode() === 'FACTURADOR_NOT_READY' y el motivo en getData()['reason']:
     * not_configured, checking, action_required, certificate_expired o disabled.
     *
     * La idempotency_key es obligatoria: un UUID v4 que guardás con la venta ANTES de llamar y
     * reusás en cada reintento. Así nunca se emiten dos facturas por una venta. Para volver a
     * intentar una factura que terminó rejected o failed, usá una clave nueva.
     *
     * @param array{
     *     dni: string,
     *     amount: int|float|string|\Stringable,
     *     metadata?: array<string, string>|\stdClass|null,
     * } $params dni, el DNI de quien te compró, como string (de 6 a 8 dígitos, como '12345678');
     *     amount, el total en pesos, hasta 2 decimales ('15000.50' o un BcMath\Number; en una
     *     Factura B, desde 0.03: con menos, el IVA da cero y la API responde PARAMETER_INVALID);
     *     metadata, hasta 20 pares de texto.
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function create(array $params, #[\SensitiveParameter] array $options): Invoice
    {
        Params::arguments('invoices->create', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'invoices->create',
            $params,
            self::CREATE_PARAMS,
            "['dni' => '12345678', 'amount' => 15000]",
        );
        // Un DNI como número nunca anda (la API lo pide como texto): falla acá, con el texto de la
        // API. Sus dígitos los valida la API, que le saca los puntos, los espacios, los guiones y
        // los ceros de adelante: el string viaja como vino.
        $dni = $values['dni'] ?? null;
        if (!is_string($dni)) {
            throw new \TypeError(
                'dni tiene que ser un DNI de 6 a 8 dígitos, como texto (por ejemplo, "12345678").',
            );
        }
        $body = [
            'dni' => $dni,
            'amount' => Encoding::canonicalAmount($values['amount'] ?? null),
            // Siempre como objeto JSON: [] vacío sería una lista, y la API la rechaza.
            'metadata' => Params::metadata($values['metadata'] ?? null),
        ];
        $opts = RequestOptions::check($options);
        $key = $opts->get('idempotency_key');
        if ($key === null) {
            throw new \TypeError(
                'invoices->create necesita idempotency_key: generá un UUID v4 (por ejemplo, con '
                . 'RayaCash\Util\Uuid::v4()), guardalo con la venta antes de llamar y reusalo en '
                . "cada reintento: \$client->invoices->create([...], ['idempotency_key' => "
                . '$clave]).',
            );
        }
        Encoding::idempotencyKey($key);
        return $this->requestor->requestAs(
            Invoice::class,
            'POST',
            self::PATH,
            body: $body,
            options: $opts,
        );
    }

    /**
     * Una factura de tu cuenta (GET /v1/invoices/:id): con status issued, con su número y su CAE.
     * Una que no existe o no es tuya da NotFoundException con getErrorCode() ===
     * 'INVOICE_NOT_FOUND'.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     * @throws \RayaCash\Exception\RayaException
     */
    public function retrieve(
        string $invoiceId,
        #[\SensitiveParameter]
        array $options = [],
    ): Invoice {
        Params::arguments('invoices->retrieve', func_num_args(), ['invoiceId', 'options']);
        return $this->requestor->requestAs(
            Invoice::class,
            'GET',
            self::PATH . '/' . Encoding::pathId('invoiceId', $invoiceId),
            options: RequestOptions::check($options),
        );
    }

    /**
     * Tus facturas, de la más nueva a la más vieja (GET /v1/invoices).
     *
     * Devuelve una página. Para recorrer todas: `foreach ($page->autoPagingIter() as $invoice)`.
     * created_gte (incluido) y created_lt (excluido) arman un rango semiabierto: los días o meses
     * seguidos no se pisan. Las fechas van como DateTimeInterface o string ISO 8601 con zona. No
     * se filtra por DNI: sería un dato personal en la URL.
     *
     * @param array{
     *     status?: 'pending'|'issued'|'rejected'|'failed'|null,
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
     * @return ListObject<Invoice>
     * @throws \RayaCash\Exception\RayaException
     */
    public function list(array $params = [], #[\SensitiveParameter] array $options = []): ListObject
    {
        Params::arguments('invoices->list', func_num_args(), ['params', 'options']);
        $values = Params::check(
            'invoices->list',
            $params,
            self::LIST_PARAMS,
            "['status' => 'issued']",
        );
        $filters = [];
        foreach (self::LIST_PARAMS as $name) {
            $filters[$name] = $values[$name] ?? null;
        }
        $query = Encoding::query($filters);
        $opts = RequestOptions::check($options);
        /** @var ListObject<Invoice> $page */
        $page = $this->requestor->requestList(self::PATH, $query, $opts);
        return $page;
    }
}
