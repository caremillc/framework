<?php

declare(strict_types=1);

namespace Careminate\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Lazy
{
}