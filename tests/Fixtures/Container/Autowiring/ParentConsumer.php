<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

use stdClass;

class ParentConsumer
{
    public function __construct(
        public readonly stdClass $dependency,
    ) {
    }
}
