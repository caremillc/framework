<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class CycleSecond
{
    public function __construct(
        public readonly CycleFirst $first,
    ) {
    }
}
