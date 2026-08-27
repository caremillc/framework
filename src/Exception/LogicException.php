<?php

declare(strict_types=1);

namespace Careminate\Exception;

use LogicException as NativeLogicException;

/**
 * Base exception for invalid framework state or incorrect API usage.
 *
 * This class is intentionally extensible for precise subsystem exceptions.
 */
class LogicException extends NativeLogicException implements ExceptionInterface
{
}