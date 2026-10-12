<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class RequiredScalar
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
