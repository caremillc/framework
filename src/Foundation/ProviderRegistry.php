<?php

declare(strict_types=1);

namespace Careminate\Foundation;

use Careminate\Container\Container;
use LogicException;

final class ProviderRegistry
{
    /** @var list<ServiceProvider> */
    private array $providers = [];

    /** @var array<class-string<ServiceProvider>, true> */
    private array $registeredClasses = [];

    private bool $registrationStarted = false;

    private bool $registrationCompleted = false;

    private bool $bootStarted = false;

    private bool $bootCompleted = false;

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function add(ServiceProvider $provider): void
    {
        if ($this->registrationStarted) {
            throw new LogicException(
                'Providers cannot be added after registration has started.'
            );
        }

        $class = $provider::class;

        if (isset($this->registeredClasses[$class])) {
            throw new LogicException(
                'A service provider of this class is already registered.'
            );
        }

        $this->providers[] = $provider;
        $this->registeredClasses[$class] = true;
    }

    public function register(): void
    {
        if ($this->registrationStarted) {
            if (!$this->registrationCompleted) {
                throw new LogicException(
                    'Provider registration previously failed or is in progress.'
                );
            }

            return;
        }

        $this->registrationStarted = true;

        foreach ($this->providers as $provider) {
            $provider->register($this->container);
        }

        $this->registrationCompleted = true;
    }

    public function boot(): void
    {
        if ($this->bootStarted) {
            if (!$this->bootCompleted) {
                throw new LogicException(
                    'Provider boot previously failed or is in progress.'
                );
            }

            return;
        }

        $this->register();

        $this->bootStarted = true;

        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }

        $this->bootCompleted = true;
    }

    public function isRegistered(): bool
    {
        return $this->registrationCompleted;
    }

    public function isBooted(): bool
    {
        return $this->bootCompleted;
    }

    public function count(): int
    {
        return count($this->providers);
    }
}
