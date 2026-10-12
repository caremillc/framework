<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

use DateTimeInterface;

final class OptionalConsumer
{
    public function __construct(
        public readonly ?DateTimeInterface $clock = null,
        public readonly int $attempts = 3,
    ) {
    }
}
