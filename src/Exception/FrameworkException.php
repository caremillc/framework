<?php

declare(strict_types=1);

namespace Careminate\Exception;

use RuntimeException;

/**
 * Extension base for specific Careminate runtime failures.
 *
 * Concrete component exceptions should be final unless they explicitly
 * define a supported extension boundary.
 *
 * @api
 */
abstract class FrameworkException extends RuntimeException implements ExceptionInterface
{
}
