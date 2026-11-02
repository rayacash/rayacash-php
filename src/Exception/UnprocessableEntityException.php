<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 422: la request está bien, pero no se pudo hacer (saldo insuficiente, destino inválido…). */
class UnprocessableEntityException extends RayaException {}
