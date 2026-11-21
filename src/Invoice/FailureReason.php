<?php

declare(strict_types=1);

namespace RayaCash\Invoice;

use RayaCash\RayaObject;

/**
 * Por qué no se emitió una factura (status rejected o failed). Decidí con code, nunca con
 * message: el texto puede cambiar.
 *
 * @property-read (
 *     'arca_rejected'|'facturador_not_ready'|'not_issued'|'numbering_conflict'
 *     |'account_unavailable'
 * ) $code Con rejected: arca_rejected (ARCA la rechazó: arca_messages dice por qué). Con failed:
 *     facturador_not_ready (tu Facturador no estaba listo para emitirla: revisalo en Negocios ›
 *     Facturador), not_issued (no pudimos emitirla y no quedó registrada en ARCA),
 *     numbering_conflict (otro sistema emitió comprobantes con el mismo punto de venta: usá uno
 *     solo para Raya) y account_unavailable (tu cuenta no puede emitir facturas en este momento:
 *     escribinos a soporte@raya.cash). Salvo con account_unavailable, después creá la factura de
 *     nuevo. Pueden sumarse valores (Invoice::FAILURE_CODE_*).
 * @property-read string $message El motivo en palabras, en castellano, para tu equipo. Puede
 *     cambiar.
 */
final class FailureReason extends RayaObject {}
