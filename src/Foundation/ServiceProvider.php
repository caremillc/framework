<?php

declare(strict_types=1);

namespace Careminate\Foundation;

use Careminate\Container\Container;

interface ServiceProvider
{
    public function register(Container $container): void;

    public function boot(Container $container): void;
}
