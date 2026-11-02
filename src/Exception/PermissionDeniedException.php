<?php

declare(strict_types=1);

namespace RayaCash\Exception;

/** 403: la cuenta no puede hacer esta operación o no tiene la API habilitada. */
class PermissionDeniedException extends RayaException {}
