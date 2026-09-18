<?php

declare(strict_types=1);

namespace Careminate\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Indicates that the requested entry is unknown to the container.
 *
 * @api
 */
final class EntryNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
