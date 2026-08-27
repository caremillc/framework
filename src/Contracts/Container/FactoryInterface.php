<?php

declare(strict_types=1);

namespace Careminate\Contracts\Container;

interface FactoryInterface
{
    public function create(ContainerInterface $container): mixed;
}