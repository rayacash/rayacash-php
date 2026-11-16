<?php

declare(strict_types=1);

namespace RayaCash;

/**
 * Un pedido de documentación sobre un cliente de Raya PSP: nuestro equipo de cumplimiento
 * necesita documentación sobre él. Llega solo en los webhooks psp.document_request.* (no tiene
 * endpoint): la subís desde Negocios › Raya PSP, en el detalle del cliente. status puede sumar
 * valores: tu código tiene que ignorarlos.
 *
 * @property-read string $id pspd_ y 24 letras o números.
 * @property-read 'psp.document_request' $object
 * @property-read string $customer El id del cliente (pspc_…).
 * @property-read 'open'|'submitted'|'closed' $status open: falta la documentación. submitted: ya
 *     la mandaste. closed: lo cerramos.
 * @property-read string $subject El asunto.
 * @property-read string $message Qué necesitamos, escrito para vos.
 * @property-read string $created_at Fecha ISO 8601 en UTC, como '2026-10-24T16:30:00Z'.
 * @property-read string|null $submitted_at Cuándo mandaste la documentación.
 * @property-read string|null $closed_at Cuándo lo cerramos.
 */
final class PspDocumentRequest extends RayaObject
{
    public const OBJECT_NAME = 'psp.document_request';

    public const STATUS_OPEN = 'open';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CLOSED = 'closed';
}
