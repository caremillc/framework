<?php

declare(strict_types=1);

namespace Flint\Foundation;

use Flint\Container\Container;

interface ServiceProvider
{
    public function register(Container $container): void;
}
