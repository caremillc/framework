<?php

declare(strict_types=1);

namespace Careminate\Container\Exception;

/**
 * Raised when registration is attempted after the container is frozen.
 *
 * @api
 */
final class FrozenContainerException extends ContainerException
{
}
