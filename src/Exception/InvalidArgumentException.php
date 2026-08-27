<?php

declare(strict_types=1);

namespace Careminate\Exception;

use InvalidArgumentException as NativeInvalidArgumentException;

/**
 * Indicates that a public framework API received an invalid argument.
 *
 * This class is intentionally extensible for subsystem-specific validation
 * exceptions.
 */
class InvalidArgumentException extends NativeInvalidArgumentException implements ExceptionInterface
{
}