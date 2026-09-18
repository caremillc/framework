<?php

declare(strict_types=1);

namespace Careminate\Container\Exception;

/**
 * Raised when singleton construction attempts to resolve a scoped service.
 *
 * @api
 */
final class LifetimeViolationException extends ContainerException
{
}
