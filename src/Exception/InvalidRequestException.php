<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 400, 405, 413: falta un parámetro, sobra uno o tiene un valor inválido. */
class InvalidRequestException extends RayaException
{
    /** El parámetro que falló (data.field). */
    public function getParam(): ?string
    {
        $field = $this->getData()['field'] ?? null;
        return is_string($field) ? $field : null;
    }
}
