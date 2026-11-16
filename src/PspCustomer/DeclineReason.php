<?php

declare(strict_types=1);

namespace RayaCash\PspCustomer;

use RayaCash\RayaObject;

/**
 * Por qué no pudimos validar o aprobar a un cliente de Raya PSP (status rejected) o crear su CVU
 * (status failed). Decidí con code, nunca con message: el texto puede cambiar.
 *
 * @property-read (
 *     'image_unavailable'|'image_invalid'|'document_unreadable'|'document_sides_mismatch'
 *     |'document_mismatch'|'identity_not_verified'|'name_mismatch'|'selfie_invalid'
 *     |'face_mismatch'|'verification_unavailable'|'not_approved'|'bank_rejected'|'psp_disabled'
 * ) $code Con rejected: image_unavailable (no pudimos bajar una imagen), image_invalid (no es un
 *     JPEG o PNG válido), document_unreadable (no pudimos leer el DNI o el sexo de su frente),
 *     document_sides_mismatch (el frente y el dorso son de DNI distintos), document_mismatch (el
 *     DNI no es el de tax_id), identity_not_verified (el RENAPER no validó la identidad),
 *     name_mismatch, selfie_invalid, face_mismatch, verification_unavailable (creá el cliente de
 *     nuevo para volver a intentar) y not_approved. Con failed, que se puede recrear:
 *     bank_rejected y psp_disabled. Pueden sumarse valores (PspCustomer::DECLINE_CODE_*).
 * @property-read string $message El motivo en palabras, en castellano, para tu equipo. Puede
 *     cambiar.
 */
final class DeclineReason extends RayaObject {}
