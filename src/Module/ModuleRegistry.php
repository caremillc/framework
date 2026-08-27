<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Exception\Module\InvalidModuleException;
use LogicException;

final class ModuleRegistry
{
    /** @var array<class-string<ModuleInterface>, RegisteredModule> */
    private array $modules = [];

    /** @var array<string, class-string<ModuleInterface>> */
    private array $names = [];

    /** @var array<string, true> */
    private array $disabled = [];

    private bool $frozen = false;

    /**
     * @param class-string<ModuleInterface>|ModuleInterface $module
     */
    public function register(string|ModuleInterface $module): self
    {
        $this->assertMutable();

        $class = is_string($module) ? $module : $module::class;

        if (!is_a($class, ModuleInterface::class, true)) {
            throw new InvalidModuleException(sprintf(
                'Module "%s" must implement %s.',
                $class,
                ModuleInterface::class,
            ));
        }

        if (isset($this->modules[$class])) {
            return $this;
        }

        $definition = $class::definition();

        if (isset($this->names[$definition->name])) {
            throw new InvalidModuleException(sprintf(
                'Module name "%s" is declared by both "%s" and "%s".',
                $definition->name,
                $this->names[$definition->name],
                $class,
            ));
        }

        $this->modules[$class] = new RegisteredModule(
            $class,
            $definition,
            is_object($module) ? $module : null,
        );

        $this->names[$definition->name] = $class;

        return $this;
    }

    public function disable(string $moduleName): self
    {
        $this->assertMutable();

        $moduleName = trim($moduleName);

        if ($moduleName === '') {
            throw new InvalidModuleException('A disabled module name cannot be empty.');
        }

        $this->disabled[$moduleName] = true;

        return $this;
    }

    public function enable(string $moduleName): self
    {
        $this->assertMutable();

        unset($this->disabled[$moduleName]);

        return $this;
    }

    public function freeze(): void
    {
        foreach (array_keys($this->disabled) as $moduleName) {
            if (!isset($this->names[$moduleName])) {
                throw new InvalidModuleException(sprintf(
                    'Disabled module "%s" is not registered or discoverable.',
                    $moduleName,
                ));
            }
        }

        $this->frozen = true;
    }

    /**
     * @return list<RegisteredModule>
     */
    public function all(): array
    {
        $modules = array_values($this->modules);

        usort(
            $modules,
            static fn (RegisteredModule $left, RegisteredModule $right): int =>
                $left->definition->name <=> $right->definition->name,
        );

        return $modules;
    }

    /**
     * @return list<RegisteredModule>
     */
    public function active(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (RegisteredModule $module): bool =>
                !$this->isDisabled($module->definition->name),
        ));
    }

    /**
     * @param class-string<ModuleInterface> $class
     */
    public function find(string $class): ?RegisteredModule
    {
        return $this->modules[$class] ?? null;
    }

    public function findByName(string $name): ?RegisteredModule
    {
        $class = $this->names[$name] ?? null;

        return $class === null ? null : $this->modules[$class];
    }

    public function isDisabled(string $moduleName): bool
    {
        return isset($this->disabled[$moduleName]);
    }

    /**
     * @return list<string>
     */
    public function disabledNames(): array
    {
        $names = array_keys($this->disabled);
        sort($names);

        return $names;
    }

    public function fingerprint(): string
    {
        $definitions = [];

        foreach ($this->all() as $module) {
            $definitions[$module->class] = $module->definition->toArray();
        }

        return hash('sha256', json_encode(
            [
                'definitions' => $definitions,
                'disabled' => $this->disabledNames(),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException(
                'The module registry is frozen after dependency planning begins.',
            );
        }
    }
}
