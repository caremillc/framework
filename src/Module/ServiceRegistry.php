<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Container\Lifetime;
use Careminate\Contracts\Container\ContainerRegistryInterface;
use Careminate\Contracts\Module\ServiceRegistryInterface;

final readonly class ServiceRegistry implements ServiceRegistryInterface
{
    public function __construct(
        private string $module,
        private ContainerRegistryInterface $container,
        private ServiceOwnershipRegistry $ownership,
        private bool $compiled = false,
    ) {
    }

    public function owner(): string
    {
        return $this->module;
    }

    public function bind(
        string $id,
        mixed $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
    ): void {
        $this->ownership->claimService($id, $this->module);

        if (!$this->compiled) {
            $this->container->bind($id, $concrete, $lifetime);
        }
    }

    public function replace(
        string $id,
        mixed $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
    ): void {
        $this->ownership->assertReplaceable($id, $this->module);

        if (!$this->compiled) {
            $this->container->replace($id, $concrete, $lifetime);
        }
    }

    public function singleton(string $id, mixed $concrete = null): void
    {
        $this->ownership->claimService($id, $this->module);

        if (!$this->compiled) {
            $this->container->singleton($id, $concrete);
        }
    }

    public function scoped(string $id, mixed $concrete = null): void
    {
        $this->ownership->claimService($id, $this->module);

        if (!$this->compiled) {
            $this->container->scoped($id, $concrete);
        }
    }

    public function instance(string $id, object $instance): void
    {
        $this->ownership->claimService($id, $this->module);

        if (!$this->compiled) {
            $this->container->instance($id, $instance);
        }
    }

    public function alias(string $id, string $alias): void
    {
        $this->ownership->claimAlias($id, $alias, $this->module);

        if (!$this->compiled) {
            $this->container->alias($id, $alias);
        }
    }

    public function tag(string $tag, string ...$services): void
    {
        foreach ($services as $service) {
            $this->ownership->claimTag($tag, $service, $this->module);
        }

        if (!$this->compiled) {
            $this->container->tag($tag, ...$services);
        }
    }

    public function contextual(
        string $consumer,
        string $dependency,
        mixed $implementation,
    ): void {
        $this->ownership->claimContextual(
            $consumer,
            $dependency,
            $this->module,
        );

        if (!$this->compiled) {
            $this->container
                ->when($consumer)
                ->needs($dependency)
                ->give($implementation);
        }
    }
}

