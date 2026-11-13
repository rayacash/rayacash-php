<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * Por qué se rechazó una verificación (solo cuando está declined). Decidí con code, nunca con
 * message: el texto puede cambiar.
 *
 * @property-read (
 *     'face_mismatch'|'registry_not_found'|'registry_data_mismatch'|'registry_unavailable'
 *     |'attempts_exhausted'
 * ) $code face_mismatch: la cara no coincide con la del documento, la del registro o la de la
 *     verificación de reference. registry_not_found: el registro no tiene a una persona con esos
 *     datos. registry_data_mismatch: los datos del documento no coinciden con los del registro.
 *     registry_unavailable: no pudimos consultar el registro (no se cobra). attempts_exhausted:
 *     la persona agotó los intentos. Pueden sumarse valores (IdentityVerification::DECLINE_CODE_*).
 * @property-read string $message El motivo en palabras, en castellano, para tu equipo. Puede
 *     cambiar.
 */
final class DeclineReason extends RayaObject {}
