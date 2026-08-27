<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Bootstrap\BootstrapSequence;
use Careminate\Application\Kernel\KernelRegistry;
use Careminate\Application\Runtime\RuntimeType;
use Careminate\Application\Termination\TerminationManager;
use Careminate\Container\Container;
use Careminate\Contracts\Application\BootstrapperInterface;
use Careminate\Contracts\Application\KernelInterface;
use Careminate\Contracts\Application\TerminationHookInterface;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Contracts\Container\ContainerRegistryInterface;
use Careminate\Exception\Application\LifecycleException;

final class ApplicationBuilder
{
    private ApplicationEnvironment $environment;

    private ApplicationPaths $paths;

    private (ContainerInterface&ContainerRegistryInterface)|null $container = null;

    private readonly BootstrapSequence $bootstrappers;

    private readonly KernelRegistry $kernels;

    private readonly TerminationManager $terminationHooks;

    private bool $built = false;

    private function __construct(string $basePath)
    {
        $this->environment = ApplicationEnvironment::local();
        $this->paths = ApplicationPaths::fromBasePath($basePath);
        $this->bootstrappers = new BootstrapSequence();
        $this->kernels = new KernelRegistry();
        $this->terminationHooks = new TerminationManager();
    }

    public static function fromBasePath(string $basePath): self
    {
        return new self($basePath);
    }

    public function environment(
        ApplicationEnvironment $environment,
    ): self {
        $this->assertNotBuilt();
        $this->environment = $environment;

        return $this;
    }

    public function paths(ApplicationPaths $paths): self
    {
        $this->assertNotBuilt();
        $this->paths = $paths;

        return $this;
    }

    public function container(
        ContainerInterface&ContainerRegistryInterface $container,
    ): self {
        $this->assertNotBuilt();
        $this->container = $container;

        return $this;
    }

    public function bootstrapper(
        BootstrapperInterface $bootstrapper,
        int $priority = 0,
    ): self {
        $this->assertNotBuilt();
        $this->bootstrappers->add($bootstrapper, $priority);

        return $this;
    }

    public function kernel(
        RuntimeType $runtime,
        KernelInterface|string $kernel,
    ): self {
        $this->assertNotBuilt();
        $this->kernels->register($runtime, $kernel);

        return $this;
    }

    public function terminationHook(
        TerminationHookInterface $hook,
        int $priority = 0,
    ): self {
        $this->assertNotBuilt();
        $this->terminationHooks->add($hook, $priority);

        return $this;
    }

    public function build(): Application
    {
        $this->assertNotBuilt();
        $this->built = true;

        return new Application(
            $this->container ?? new Container(),
            $this->environment,
            $this->paths,
            new ApplicationStateMachine(),
            $this->bootstrappers,
            $this->kernels,
            $this->terminationHooks,
        );
    }

    private function assertNotBuilt(): void
    {
        if ($this->built) {
            throw LifecycleException::builderAlreadyUsed();
        }
    }
}
