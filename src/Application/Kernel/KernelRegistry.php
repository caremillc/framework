<?php

declare(strict_types=1);

namespace Careminate\Application\Kernel;

use Careminate\Application\Runtime\RuntimeType;
use Careminate\Contracts\Application\KernelInterface;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Exception\Application\ApplicationException;
use Careminate\Exception\Application\KernelNotFoundException;

final class KernelRegistry
{
    /**
     * @var array<string, KernelInterface|class-string>
     */
    private array $kernels = [];

    private bool $frozen = false;

    public function register(
        RuntimeType $runtime,
        KernelInterface|string $kernel,
    ): void {
        if ($this->frozen) {
            throw new ApplicationException(
                'The application kernel registry is frozen.',
            );
        }

        if (isset($this->kernels[$runtime->value])) {
            throw new ApplicationException(
                sprintf(
                    'A kernel is already registered for runtime "%s".',
                    $runtime->value,
                ),
            );
        }

        $this->kernels[$runtime->value] = $kernel;
    }

    public function replace(
        RuntimeType $runtime,
        KernelInterface|string $kernel,
    ): void {
        if ($this->frozen) {
            throw new ApplicationException(
                'The application kernel registry is frozen.',
            );
        }

        $this->kernels[$runtime->value] = $kernel;
    }

    public function resolve(
        RuntimeType $runtime,
        ContainerInterface $container,
    ): KernelInterface {
        $registered = $this->kernels[$runtime->value] ?? null;

        if ($registered === null) {
            throw KernelNotFoundException::forRuntime($runtime);
        }

        $kernel = is_string($registered)
            ? $container->get($registered)
            : $registered;

        if (!$kernel instanceof KernelInterface) {
            throw KernelNotFoundException::invalidKernel(
                $runtime,
                is_object($kernel) ? $kernel::class : get_debug_type($kernel),
            );
        }

        return $kernel;
    }

    public function has(RuntimeType $runtime): bool
    {
        return isset($this->kernels[$runtime->value]);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
