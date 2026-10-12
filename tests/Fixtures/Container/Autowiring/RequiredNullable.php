<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

use DateTimeInterface;

final class RequiredNullable
{
    public function __construct(
        public readonly ?DateTimeInterface $clock,
    ) {
    }
}
