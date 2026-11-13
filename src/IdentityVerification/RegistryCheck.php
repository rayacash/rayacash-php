<?php

declare(strict_types=1);

namespace RayaCash\IdentityVerification;

use RayaCash\RayaObject;

/**
 * La validación contra el Registro Nacional de las Personas, RENAPER (checks->registry). En una
 * biometric es not_applicable.
 *
 * @property-read 'passed'|'failed'|'pending'|'unavailable'|'not_applicable' $status Como el de
 *     DocumentCheck. unavailable: el registro no respondió en 24 horas; la verificación queda
 *     declined con registry_unavailable y no se cobra. Pueden sumarse valores.
 * @property-read bool|null $data_match Si los datos leídos del DNI coinciden con los del registro.
 * @property-read 'hit'|'accepted'|'failed'|null $face_match La cara contra la del registro. hit:
 *     el registro confirmó que es la misma persona. accepted: no la confirmó del todo, pero el
 *     parecido alcanza el mínimo que aceptamos. failed: no es la misma persona. Pueden sumarse
 *     valores.
 */
final class RegistryCheck extends RayaObject {}
