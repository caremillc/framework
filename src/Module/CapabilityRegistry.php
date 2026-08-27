<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Exception\Module\DuplicateCapabilityProviderException;
use LogicException;

final class CapabilityRegistry
{
    /**
     * @var array<string, array{
     *     module: string,
     *     class: class-string<ModuleInterface>
     * }>
     */
    private array $providers = [];

    private bool $frozen = false;

    public static function fromModules(ModuleRegistry $modules): self
    {
        $registry = new self();

        foreach ($modules->active() as $module) {
            foreach ($module->definition->providedCapabilities as $capability) {
                $registry->provide(
                    $capability,
                    $module->definition->name,
                    $module->class,
                );
            }
        }

        $registry->freeze();

        return $registry;
    }

    /**
     * @param class-string<ModuleInterface> $moduleClass
     */
    public function provide(
        string $capability,
        string $moduleName,
        string $moduleClass,
    ): void {
        if ($this->frozen) {
            throw new LogicException('The capability registry is frozen.');
        }

        $existing = $this->providers[$capability] ?? null;

        if ($existing !== null && $existing['class'] !== $moduleClass) {
            throw new DuplicateCapabilityProviderException(
                $capability,
                $existing['module'],
                $moduleName,
            );
        }

        $this->providers[$capability] = [
            'module' => $moduleName,
            'class' => $moduleClass,
        ];
    }

    public function has(string $capability): bool
    {
        return isset($this->providers[$capability]);
    }

    /**
     * @return class-string<ModuleInterface>|null
     */
    public function providerClass(string $capability): ?string
    {
        return $this->providers[$capability]['class'] ?? null;
    }

    public function providerName(string $capability): ?string
    {
        return $this->providers[$capability]['module'] ?? null;
    }

    /**
     * @return array<string, array{module: string, class: class-string<ModuleInterface>}>
     */
    public function all(): array
    {
        $providers = $this->providers;
        ksort($providers);

        return $providers;
    }

    private function freeze(): void
    {
        $this->frozen = true;
    }
}
