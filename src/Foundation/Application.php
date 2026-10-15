<?php

declare(strict_types=1);

namespace Flint\Foundation;

use Closure;
use Flint\Container\Container;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class Application
{
    private readonly string $basePath;

    private readonly Container $container;

    /** @var list<BootableServiceProvider> */
    private array $bootableProviders = [];

    private string $state = 'registering';

    /** @var list<Closure(): void> */
    private array $terminationCallbacks = [];

    public function __construct(string $basePath)
    {
        if ($basePath === '' || str_contains($basePath, "\0")) {
            throw new InvalidArgumentException(
                'The application base path must be a non-empty directory path without null bytes.',
            );
        }

        $resolvedPath = realpath($basePath);

        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new InvalidArgumentException(
                'The application base path must reference an existing directory.',
            );
        }

        $this->basePath = $resolvedPath;
        $this->container = new Container();
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function register(ServiceProvider $provider): void
    {
        if ($this->state !== 'registering') {
            throw new LogicException(
                'Providers can only be registered before application boot.',
            );
        }

        // Prevent reentrant registration or boot during registration.
        $this->state = 'registering-provider';

        try {
            $provider->register($this->container);
        } catch (Throwable $exception) {
            $this->state = 'failed';

            throw $exception;
        }

        if ($provider instanceof BootableServiceProvider) {
            $this->bootableProviders[] = $provider;
        }

        $this->state = 'registering';
    }

    public function boot(): void
    {
        if ($this->state === 'booted') {
            return;
        }

        if ($this->state !== 'registering') {
            throw new LogicException(
                'Application boot requires completed registration and no prior failure.',
            );
        }

        $this->state = 'booting';

        try {
            foreach ($this->bootableProviders as $provider) {
                $provider->boot($this->container);
            }
        } catch (Throwable $exception) {
            $this->state = 'failed';

            throw $exception;
        }

        $this->state = 'booted';
    }

    public function isBooted(): bool
    {
        return $this->state === 'booted';
    }

    /**
     * @param Closure(): void $callback
     */
    public function onTerminate(Closure $callback): void
    {
        if (
            $this->state === 'terminating'
            || $this->state === 'terminated'
        ) {
            throw new LogicException(
                'Cleanup callbacks cannot be added after termination starts.',
            );
        }

        $this->terminationCallbacks[] = $callback;
    }

    public function terminate(): void
    {
        if ($this->state === 'terminated') {
            return;
        }

        if (
            $this->state === 'registering-provider'
            || $this->state === 'booting'
            || $this->state === 'terminating'
        ) {
            throw new LogicException(
                'Application termination cannot interrupt an active lifecycle operation.',
            );
        }

        $this->state = 'terminating';

        $callbacks = array_reverse($this->terminationCallbacks);
        $this->terminationCallbacks = [];
        $firstFailure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
            }
        }

        $this->state = 'terminated';

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    public function isTerminated(): bool
    {
        return $this->state === 'terminated';
    }

}
