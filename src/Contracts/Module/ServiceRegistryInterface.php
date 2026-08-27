<?php

declare(strict_types=1);

namespace Careminate\Contracts\Module;

use Careminate\Container\Lifetime;

interface ServiceRegistryInterface
{
    public function owner(): string;

    public function bind(
        string $id,
        mixed $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
    ): void;

    public function replace(
        string $id,
        mixed $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
    ): void;

    public function singleton(string $id, mixed $concrete = null): void;

    public function scoped(string $id, mixed $concrete = null): void;

    public function instance(string $id, object $instance): void;

    public function alias(string $id, string $alias): void;

    public function tag(string $tag, string ...$services): void;

    public function contextual(
        string $consumer,
        string $dependency,
        mixed $implementation,
    ): void;
}
