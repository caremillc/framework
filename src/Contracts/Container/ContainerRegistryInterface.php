<?php

declare(strict_types=1);

namespace Careminate\Contracts\Container;

use Careminate\Container\Lifetime;

interface ContainerRegistryInterface
{
    public function bind(
        string $id,
        string|callable|null $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
        bool $lazy = false,
    ): static;

    public function replace(
        string $id,
        string|callable|null $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
        bool $lazy = false,
    ): static;

    public function singleton(
        string $id,
        string|callable|null $concrete = null,
    ): static;

    public function scoped(
        string $id,
        string|callable|null $concrete = null,
    ): static;

    public function lazy(
        string $id,
        ?string $concrete = null,
        Lifetime $lifetime = Lifetime::Singleton,
    ): static;

    public function factory(
        string $id,
        string|callable|FactoryInterface $factory,
        Lifetime $lifetime = Lifetime::Transient,
    ): static;

    public function instance(string $id, mixed $instance): static;

    public function alias(string $alias, string $id): static;

    /**
     * @param string|list<string> $ids
     */
    public function tag(string|array $ids, string $tag): static;

    public function when(string $consumer): ContextualBindingBuilderInterface;
}
