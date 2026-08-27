<?php

declare(strict_types=1);

namespace Careminate\Contracts\Container;

interface ScopedContainerInterface extends ContainerInterface
{
    public function scopeName(): string;

    public function close(): void;

    public function isClosed(): bool;
}