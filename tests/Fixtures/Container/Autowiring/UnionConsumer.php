<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class UnionConsumer
{
    public function __construct(
        public readonly int|string $value,
    ) {
    }
}
