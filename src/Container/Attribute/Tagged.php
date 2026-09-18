<?php

declare(strict_types=1);

namespace Careminate\Container\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Selects a tagged collection for an array constructor parameter.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Tagged
{
    public function __construct(public readonly string $tag)
    {
        if ($tag === '') {
            throw new InvalidArgumentException(
                'An injection tag must not be empty.',
            );
        }
    }
}
