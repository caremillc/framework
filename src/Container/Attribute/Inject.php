<?php

declare(strict_types=1);

namespace Careminate\Container\Attribute;

use Attribute;
use Careminate\Exception\InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    public function __construct(public string $id)
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException(
                'The Inject attribute requires a non-empty service identifier.',
            );
        }
    }
}