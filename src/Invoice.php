<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\InvoiceService;

/**
 * Una factura electrónica de ARCA que emitís con el Facturador, a nombre de quien te compró
 * (identificado con su DNI): Factura B si sos Responsable Inscripto (el monto incluye el IVA del
 * 21 %) o Factura C si sos monotributista (sin IVA).
 *
 * La creás desde tu servidor, con tu clave secreta, y la emitimos en segundo plano con el
 * certificado, el punto de venta y el concepto de tu Facturador: vuelve pending y te avisamos el
 * resultado con los webhooks invoice.issued, invoice.rejected o invoice.failed, que traen este
 * mismo objeto. Dala por emitida solo cuando status es issued: ahí tiene number, cae,
 * cae_expires_on e issued_on. status y los códigos de failure_reason pueden sumar valores: tu
 * código tiene que ignorarlos.
 *
 * @property-read string $id inv_ y 24 letras o números.
 * @property-read 'invoice' $object
 * @property-read 'B'|'C' $type B: Factura B (Responsable Inscripto: el IVA va discriminado en
 *     net_amount y vat_amount). C: Factura C (Monotributo: sin IVA, con vat_amount en null). Sale
 *     de la condición frente al IVA de tu Facturador.
 * @property-read 'pending'|'issued'|'rejected'|'failed' $status pending: la estamos emitiendo; si
 *     ARCA no responde, seguimos hasta confirmar el resultado. issued: ARCA la autorizó y le asignó
 *     su CAE. rejected: ARCA la rechazó (arca_messages dice por qué): no se emitió. failed: no
 *     pudimos emitirla y no quedó registrada en ARCA (failure_reason dice por qué). Para volver a
 *     intentar una rejected o una failed, creá otra factura, con una idempotency_key nueva.
 * @property-read int|float $amount El total, en pesos: lo que pagó quien te compró.
 * @property-read int|float $net_amount El importe neto: en una B, amount sin el IVA; en una C,
 *     igual a amount.
 * @property-read int|float|null $vat_amount El IVA (21 %) de una B: net_amount + vat_amount es
 *     amount. null en una C.
 * @property-read string $currency Siempre 'ARS'.
 * @property-read string $dni El DNI de quien te compró: de 6 a 8 dígitos, sin puntos ni ceros
 *     adelante.
 * @property-read int $point_of_sale Tu punto de venta de ARCA.
 * @property-read int|null $number El número del comprobante en tu punto de venta: null hasta que
 *     se emite.
 * @property-read 'products'|'services'|'products_and_services' $concept El concepto de tu
 *     Facturador: productos, servicios o productos y servicios.
 * @property-read string|null $cae El Código de Autorización Electrónico que asignó ARCA (14
 *     dígitos): solo cuando está issued.
 * @property-read string|null $cae_expires_on El vencimiento del CAE, como '2026-11-28'.
 * @property-read string|null $issued_on La fecha del comprobante, como '2026-11-18': la que
 *     registró ARCA, en hora de Argentina.
 * @property-read Invoice\FailureReason|null $failure_reason Por qué no se emitió: solo con status
 *     rejected o failed.
 * @property-read list<Invoice\ArcaMessage> $arca_messages Los errores y las observaciones que
 *     mandó ARCA, tal cual ([] si no mandó ninguno): una emitida también puede traer
 *     observaciones.
 * @property-read array<array-key, string> $metadata Tus pares clave-valor ([] si no mandaste).
 *     PHP convierte las claves que parecen enteros en int, y "0", "1"… en una lista: para
 *     mandarla de vuelta, (object) $invoice->metadata.
 * @property-read string|null $issued_at Cuándo quedó emitida (ISO 8601 en UTC).
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-11-18T15:12:38Z'.
 */
final class Invoice extends RayaObject
{
    public const OBJECT_NAME = 'invoice';

    /** Factura B: Responsable Inscripto, con el IVA del 21 % incluido en amount. */
    public const TYPE_B = 'B';
    /** Factura C: Monotributo, sin IVA. */
    public const TYPE_C = 'C';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    public const CONCEPT_PRODUCTS = 'products';
    public const CONCEPT_SERVICES = 'services';
    public const CONCEPT_PRODUCTS_AND_SERVICES = 'products_and_services';

    public const FAILURE_CODE_ARCA_REJECTED = 'arca_rejected';
    public const FAILURE_CODE_FACTURADOR_NOT_READY = 'facturador_not_ready';
    public const FAILURE_CODE_NOT_ISSUED = 'not_issued';
    public const FAILURE_CODE_NUMBERING_CONFLICT = 'numbering_conflict';
    public const FAILURE_CODE_ACCOUNT_UNAVAILABLE = 'account_unavailable';

    protected const INNER_CLASSES = [
        'failure_reason' => Invoice\FailureReason::class,
        'arca_messages' => Invoice\ArcaMessage::class,
    ];
    protected const PLAIN_FIELDS = ['metadata'];

    // --- Estilo global (Invoice::create/retrieve/list); ver InvoiceService. ---

    /**
     * Crea una factura y la emite en segundo plano, con tu clave secreta (RayaCash::setApiKey() o
     * api_key). La idempotency_key es obligatoria.
     *
     * @param array{
     *     dni: string,
     *     amount: int|float|string|\Stringable,
     *     metadata?: array<string, string>|\stdClass|null,
     * } $params
     * @param array{
     *     idempotency_key: string,
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function create(array $params, #[\SensitiveParameter] array $options): Invoice
    {
        Params::arguments('Invoice::create', func_num_args(), ['params', 'options']);
        return (new InvoiceService(GlobalState::requestor()))->create($params, $options);
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $invoiceId,
        #[\SensitiveParameter]
        array $options = [],
    ): Invoice {
        Params::arguments('Invoice::retrieve', func_num_args(), ['invoiceId', 'options']);
        return (new InvoiceService(GlobalState::requestor()))->retrieve($invoiceId, $options);
    }

    /**
     * @param array{
     *     status?: 'pending'|'issued'|'rejected'|'failed'|null,
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
     * @return ListObject<Invoice>
     */
    public static function list(
        array $params = [],
        #[\SensitiveParameter]
        array $options = [],
    ): ListObject {
        Params::arguments('Invoice::list', func_num_args(), ['params', 'options']);
        return (new InvoiceService(GlobalState::requestor()))->list($params, $options);
    }
}
