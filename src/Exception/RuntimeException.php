<?php

declare(strict_types=1);

namespace Careminate\Exception;

use RuntimeException as NativeRuntimeException;

/**
 * Base exception for failures detected while the framework is running.
 *
 * This class is intentionally extensible for precise subsystem exceptions.
 */
class RuntimeException extends NativeRuntimeException implements ExceptionInterface
{
}
