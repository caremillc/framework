<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

use stdClass;

final class ObjectConsumer
{
    public function __construct(
        public readonly stdClass $dependency,
    ) {
    }
}
