<?php

declare(strict_types=1);

namespace Careminate\Http\Exception;

use InvalidArgumentException;

/**
 * Invalid inbound request whose body exceeds the configured byte limit.
 *
 * Extends InvalidArgumentException to preserve the existing factory contract.
 */
final class RequestBodyTooLargeException extends InvalidArgumentException
{
}
