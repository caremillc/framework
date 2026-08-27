<?php

declare(strict_types=1);

namespace Careminate\Exception;

use UnexpectedValueException as NativeUnexpectedValueException;

/**
 * Indicates that a value could not be converted into the required form.
 *
 * This class is intentionally extensible for precise conversion failures.
 */
class UnexpectedValueException extends NativeUnexpectedValueException implements ExceptionInterface
{
}