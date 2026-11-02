<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 5xx, 408 o una respuesta inesperada: falló algo del lado de Raya. */
class ApiException extends RayaException {}
