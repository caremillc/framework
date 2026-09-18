<?php

declare(strict_types=1);

namespace Careminate\Application\Exception;

use Careminate\Exception\FrameworkException;

/**
 * Raised when an application lifecycle transition is not permitted.
 *
 * @internal
 */
final class InvalidLifecycleTransitionException extends FrameworkException
{
}
