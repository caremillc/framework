<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class CycleFirst
{
    public function __construct(
        public readonly CycleSecond $second,
    ) {
    }
}
