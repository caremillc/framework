<?php

declare(strict_types=1);

namespace Careminate\Container\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Selects a registered service for a constructor parameter.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(public readonly string $id)
    {
        if ($id === '') {
            throw new InvalidArgumentException(
                'An injection identifier must not be empty.',
            );
        }
    }
}
