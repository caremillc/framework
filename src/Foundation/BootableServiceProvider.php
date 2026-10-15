<?php

declare(strict_types=1);

namespace Flint\Foundation;

use Flint\Container\Container;

interface BootableServiceProvider extends ServiceProvider
{
    public function boot(Container $container): void;
}
