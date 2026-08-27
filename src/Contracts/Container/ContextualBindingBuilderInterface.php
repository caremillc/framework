<?php

declare(strict_types=1);

namespace Careminate\Contracts\Container;

interface ContextualBindingBuilderInterface
{
    public function needs(string $dependency): static;

    public function give(mixed $implementation): ContainerRegistryInterface;
}