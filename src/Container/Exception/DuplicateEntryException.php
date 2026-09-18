<?php

declare(strict_types=1);

namespace Careminate\Container\Exception;

/**
 * Indicates an attempt to register an identifier that already exists.
 *
 * @api
 */
final class DuplicateEntryException extends ContainerException
{
}
