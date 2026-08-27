<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Application\Bootstrap\BootstrapContext;
use Careminate\Contracts\Module\ModuleDiscoveryInterface;
use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Contracts\Module\ServiceProviderInterface;
use Careminate\Exception\Module\InvalidModuleException;
use Careminate\Exception\Module\ModuleException;
use Careminate\Exception\Module\ModuleLifecycleException;
use LogicException;
use Throwable;

final class ModuleManager
{
    private ModuleRegistry $modules;

    private ModuleDependencyGraph $graph;

    private ServiceOwnershipRegistry $ownership;

    /** @var list<ModuleDiscoveryInterface> */
    private array $discoveries = [];

    /** @var array<string, ModuleDiagnostic> */
    private array $diagnostics = [];

    private ModuleManagerState $state = ModuleManagerState::New;

    private ?CapabilityRegistry $capabilities = null;

    private ?ModulePlan $plan = null;

    private ?ModulePlan $cachedPlan = null;

    private bool $compiled = false;

    public function __construct(
        ?ModuleRegistry $modules = null,
        ?ModuleDependencyGraph $graph = null,
        ?ServiceOwnershipRegistry $ownership = null,
    ) {
        $this->modules = $modules ?? new ModuleRegistry();
        $this->graph = $graph ?? new ModuleDependencyGraph();
        $this->ownership = $ownership ?? new ServiceOwnershipRegistry();
    }

    public function discoverUsing(ModuleDiscoveryInterface $discovery): self
    {
        $this->assertConfigurable();

        $this->discoveries[] = $discovery;

        return $this;
    }

    /**
     * @param class-string<ModuleInterface>|ModuleInterface $module
     */
    public function register(string|ModuleInterface $module): self
    {
        $this->assertConfigurable();

        $this->modules->register($module);

        return $this;
    }

    public function disable(string $moduleName): self
    {
        $this->assertConfigurable();

        $this->modules->disable($moduleName);

        return $this;
    }

    public function enable(string $moduleName): self
    {
        $this->assertConfigurable();

        $this->modules->enable($moduleName);

        return $this;
    }

    public function useCachedPlan(?ModulePlan $plan): self
    {
        $this->assertConfigurable();

        $this->cachedPlan = $plan;

        return $this;
    }

    public function compiled(bool $compiled = true): self
    {
        $this->assertConfigurable();

        $this->compiled = $compiled;

        return $this;
    }

    public function bootstrap(BootstrapContext $bootstrap): void
    {
        $this->assertConfigurable();
        $this->state = ModuleManagerState::Discovering;

        try {
            foreach ($this->discoveries as $discovery) {
                foreach ($discovery->discover() as $module) {
                    $this->modules->register($module);
                }
            }

            $this->initializeDiagnostics();
            $this->modules->freeze();

            $this->capabilities = CapabilityRegistry::fromModules(
                $this->modules,
            );

            if ($this->cachedPlan !== null) {
                $this->cachedPlan->assertCompatible($this->modules);
                $this->plan = $this->cachedPlan;
            } else {
                $this->plan = $this->graph->plan(
                    $this->modules,
                    $this->capabilities,
                );
            }

            $this->state = ModuleManagerState::Planned;
            $this->markPlannedModules();

            /** @var array<class-string<ModuleInterface>, ModuleInterface> $instances */
            $instances = [];

            /**
             * @var array<class-string<ModuleInterface>, list<ServiceProviderInterface>>
             */
            $providers = [];

            /** @var array<class-string<ModuleInterface>, ModuleContext> $contexts */
            $contexts = [];

            foreach ($this->plan->orderedModules as $class) {
                $registered = $this->modules->find($class);

                if ($registered === null) {
                    throw new InvalidModuleException(sprintf(
                        'Planned module "%s" is not registered.',
                        $class,
                    ));
                }

                $instance = $registered->instance
                    ?? $bootstrap->container->get($class);

                if (!$instance instanceof ModuleInterface) {
                    throw new InvalidModuleException(sprintf(
                        'Container entry "%s" must implement %s.',
                        $class,
                        ModuleInterface::class,
                    ));
                }

                $serviceRegistry = new ServiceRegistry(
                    $registered->definition->name,
                    $bootstrap->container,
                    $this->ownership,
                    $this->compiled,
                );

                $context = new ModuleContext(
                    $registered->definition,
                    $bootstrap->container,
                    $serviceRegistry,
                    $this->capabilities,
                    $bootstrap->environment,
                    $bootstrap->paths,
                    $this->compiled,
                );

                $instances[$class] = $instance;
                $contexts[$class] = $context;
                $providers[$class] = [];

                foreach ($registered->definition->providers as $providerClass) {
                    $provider = $bootstrap->container->get($providerClass);

                    if (!$provider instanceof ServiceProviderInterface) {
                        throw new InvalidModuleException(sprintf(
                            'Container entry "%s" must implement %s.',
                            $providerClass,
                            ServiceProviderInterface::class,
                        ));
                    }

                    $providers[$class][] = $provider;
                }
            }

            $this->state = ModuleManagerState::Registering;

            foreach ($this->plan->orderedModules as $class) {
                $moduleName = $contexts[$class]->definition->name;

                try {
                    foreach ($providers[$class] as $provider) {
                        $provider->register($contexts[$class]);
                    }

                    $instances[$class]->register($contexts[$class]);

                    $this->setDiagnostic(
                        $class,
                        ModuleStatus::Registered,
                        'Services registered.',
                    );
                } catch (Throwable $exception) {
                    $this->setDiagnostic(
                        $class,
                        ModuleStatus::Failed,
                        'Registration failed: ' . $exception->getMessage(),
                    );

                    throw new ModuleLifecycleException(
                        $moduleName,
                        'register',
                        $exception,
                    );
                }
            }

            $this->state = ModuleManagerState::Booting;

            foreach ($this->plan->orderedModules as $position => $class) {
                $moduleName = $contexts[$class]->definition->name;

                try {
                    foreach ($providers[$class] as $provider) {
                        $provider->boot($contexts[$class]);
                    }

                    $instances[$class]->boot($contexts[$class]);

                    $this->setDiagnostic(
                        $class,
                        ModuleStatus::Booted,
                        'Module booted successfully.',
                        $position + 1,
                    );
                } catch (Throwable $exception) {
                    $this->setDiagnostic(
                        $class,
                        ModuleStatus::Failed,
                        'Boot failed: ' . $exception->getMessage(),
                        $position + 1,
                    );

                    throw new ModuleLifecycleException(
                        $moduleName,
                        'boot',
                        $exception,
                    );
                }
            }

            $this->state = ModuleManagerState::Booted;
        } catch (Throwable $exception) {
            $this->state = ModuleManagerState::Failed;

            if ($exception instanceof ModuleException) {
                throw $exception;
            }

            throw new ModuleLifecycleException(
                'module-manager',
                $this->state->value,
                $exception,
            );
        }
    }

    public function state(): ModuleManagerState
    {
        return $this->state;
    }

    public function plan(): ModulePlan
    {
        if ($this->plan === null) {
            throw new LogicException(
                'The module plan is unavailable before module planning.',
            );
        }

        return $this->plan;
    }

    public function capabilities(): CapabilityRegistry
    {
        if ($this->capabilities === null) {
            throw new LogicException(
                'Capabilities are unavailable before module discovery.',
            );
        }

        return $this->capabilities;
    }

    public function ownership(): ServiceOwnershipRegistry
    {
        return $this->ownership;
    }

    /**
     * @return list<ModuleDiagnostic>
     */
    public function diagnostics(): array
    {
        $diagnostics = array_values($this->diagnostics);

        usort(
            $diagnostics,
            static fn (
                ModuleDiagnostic $left,
                ModuleDiagnostic $right,
            ): int => $left->name <=> $right->name,
        );

        return $diagnostics;
    }

    private function initializeDiagnostics(): void
    {
        foreach ($this->modules->all() as $module) {
            $disabled = $this->modules->isDisabled(
                $module->definition->name,
            );

            $this->diagnostics[$module->class] = new ModuleDiagnostic(
                $module->definition->name,
                $module->class,
                $disabled ? ModuleStatus::Disabled : ModuleStatus::Discovered,
                $disabled
                    ? 'Module is disabled and will not register or boot.'
                    : 'Module discovered.',
            );
        }
    }

    private function markPlannedModules(): void
    {
        if ($this->plan === null) {
            return;
        }

        foreach ($this->plan->orderedModules as $position => $class) {
            $this->setDiagnostic(
                $class,
                ModuleStatus::Planned,
                'Module dependency order resolved.',
                $position + 1,
            );
        }
    }

    private function setDiagnostic(
        string $class,
        ModuleStatus $status,
        string $message,
        ?int $bootPosition = null,
    ): void {
        $module = $this->modules->find($class);

        if ($module === null) {
            return;
        }

        $this->diagnostics[$class] = new ModuleDiagnostic(
            $module->definition->name,
            $class,
            $status,
            $message,
            $bootPosition,
        );
    }

    private function assertConfigurable(): void
    {
        if ($this->state !== ModuleManagerState::New) {
            throw new LogicException(sprintf(
                'The module manager cannot be configured while in state "%s".',
                $this->state->value,
            ));
        }
    }
}
