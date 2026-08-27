<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Contracts\Container\ContainerRegistryInterface;
use Careminate\Contracts\Container\ContextualBindingBuilderInterface;
use Careminate\Exception\Container\InvalidDefinitionException;

final class ContextualBindingBuilder implements ContextualBindingBuilderInterface
{
    private ?string $dependency = null;

    public function __construct(
        private readonly Container $container,
        private readonly string $consumer,
    ) {
    }

    public function needs(string $dependency): static
    {
        if (trim($dependency) === '') {
            throw InvalidDefinitionException::emptyIdentifier();
        }

        $this->dependency = $dependency;

        return $this;
    }

    public function give(mixed $implementation): ContainerRegistryInterface
    {
        if ($this->dependency === null) {
            throw new InvalidDefinitionException(
                sprintf(
                    'A contextual dependency must be selected for consumer "%s" before give() is called.',
                    $this->consumer,
                ),
            );
        }

        $this->container->addContextualBinding(
            $this->consumer,
            $this->dependency,
            $implementation,
        );

        return $this->container;
    }
}
