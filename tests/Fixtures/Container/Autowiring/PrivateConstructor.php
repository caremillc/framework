<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Container\Autowiring;

final class PrivateConstructor
{
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }
}
