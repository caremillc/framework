<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class ReferenceConsumer
{
    public readonly string $name;

    public function __construct(string &$name)
    {
        $this->name = $name;
    }
}
