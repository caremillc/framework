<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

use Countable;
use Iterator;

final class IntersectionConsumer
{
    /**
     * @param Countable&Iterator<int, string> $items
     */
    public function __construct(
        public readonly Countable&Iterator $items,
    ) {
    }
}
