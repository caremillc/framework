<?php

declare(strict_types=1);

namespace Careminate\Foundation;

use Careminate\Container\Container;
use Closure;
use LogicException;
use Throwable;

final class Application
{
    private const string CREATED = 'created';
    private const string BOOTING = 'booting';
    private const string BOOTED = 'booted';
    private const string FAILED = 'failed';
    private const string TERMINATED = 'terminated';

    private string $state = self::CREATED;

    /**
     * @var list<Closure(self): void>
     */
    private array $bootCallbacks = [];

    /**
     * @var list<Closure(self): void>
     */
    private array $terminationCallbacks = [];

    private readonly Container $container;

    private readonly ProviderRegistry $providers;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
        $this->providers = new ProviderRegistry($this->container);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function registerProvider(ServiceProvider $provider): void
    {
        if ($this->state !== self::CREATED) {
            throw new LogicException(
                'Service providers must be registered before booting.'
            );
        }

        $this->providers->add($provider);
    }

    /**
     * @param Closure(self): void $callback
     */
    public function onBoot(Closure $callback): void
    {
        if ($this->state !== self::CREATED) {
            throw new LogicException(
                'Boot callbacks must be registered before booting.'
            );
        }

        $this->bootCallbacks[] = $callback;
    }

    /**
     * @param Closure(self): void $callback
     */
    public function onTerminate(Closure $callback): void
    {
        if ($this->state === self::TERMINATED) {
            throw new LogicException(
                'Cannot register cleanup after termination.'
            );
        }

        $this->terminationCallbacks[] = $callback;
    }

    public function boot(): void
    {
        if ($this->state === self::BOOTED) {
            return;
        }

        if ($this->state !== self::CREATED) {
            throw new LogicException(
                sprintf(
                    'Cannot boot application in state "%s".',
                    $this->state
                )
            );
        }

        $this->state = self::BOOTING;

        try {
            $this->providers->boot();

            foreach ($this->bootCallbacks as $callback) {
                $callback($this);
            }

            $this->state = self::BOOTED;
        } catch (Throwable $exception) {
            $this->state = self::FAILED;

            throw $exception;
        }
    }

    public function terminate(): void
    {
        if ($this->state === self::TERMINATED) {
            return;
        }

        if ($this->state === self::BOOTING) {
            throw new LogicException(
                'Cannot terminate while application is booting.'
            );
        }

        $this->state = self::TERMINATED;

        $failure = null;

        foreach (array_reverse($this->terminationCallbacks) as $callback) {
            try {
                $callback($this);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function isBooted(): bool
    {
        return $this->state === self::BOOTED;
    }

    public function isTerminated(): bool
    {
        return $this->state === self::TERMINATED;
    }

    public function hasFailed(): bool
    {
        return $this->state === self::FAILED;
    }
}
