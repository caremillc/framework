<?php

declare(strict_types=1);

namespace Careminate\Container\Attribute;

use Attribute;
use Careminate\Exception\InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Tagged
{
    public function __construct(public string $tag)
    {
        if (trim($tag) === '') {
            throw new InvalidArgumentException(
                'The Tagged attribute requires a non-empty tag.',
            );
        }
    }
}