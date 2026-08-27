<?php

declare(strict_types=1);

namespace Careminate\Module;

use Composer\Semver\VersionParser;
use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Contracts\Module\ServiceProviderInterface;
use Careminate\Exception\Module\InvalidModuleException;
use UnexpectedValueException;

final readonly class ModuleDefinition
{
    /**
     * @param list<ModuleDependency> $requiredModules
     * @param list<ModuleDependency> $optionalModules
     * @param list<string> $requiredCapabilities
     * @param list<string> $optionalCapabilities
     * @param list<string> $providedCapabilities
     * @param list<class-string<ServiceProviderInterface>> $providers
     */
    private function __construct(
        public string $name,
        public string $version,
        public array $requiredModules,
        public array $optionalModules,
        public array $requiredCapabilities,
        public array $optionalCapabilities,
        public array $providedCapabilities,
        public array $providers,
    ) {
    }

    public static function named(string $name): self
    {
        $name = trim($name);

        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $name) !== 1) {
            throw new InvalidModuleException(sprintf(
                'Invalid module name "%s". Use lowercase letters, numbers, dots, underscores, or hyphens.',
                $name,
            ));
        }

        return new self($name, '0.0.0', [], [], [], [], [], []);
    }

    public function version(string $version): self
    {
        $version = trim($version);

        try {
            (new VersionParser())->normalize($version);
        } catch (UnexpectedValueException $exception) {
            throw new InvalidModuleException(sprintf(
                'Module "%s" has invalid version "%s": %s',
                $this->name,
                $version,
                $exception->getMessage(),
            ));
        }

        return new self(
            $this->name,
            $version,
            $this->requiredModules,
            $this->optionalModules,
            $this->requiredCapabilities,
            $this->optionalCapabilities,
            $this->providedCapabilities,
            $this->providers,
        );
    }

    /**
     * @param class-string<ModuleInterface> $module
     */
    public function requires(string $module, string $constraint = '*'): self
    {
        $this->assertModuleClass($module);
        $this->assertConstraint($constraint);

        return new self(
            $this->name,
            $this->version,
            $this->appendDependency(
                $this->requiredModules,
                new ModuleDependency($module, $constraint, true),
            ),
            $this->optionalModules,
            $this->requiredCapabilities,
            $this->optionalCapabilities,
            $this->providedCapabilities,
            $this->providers,
        );
    }

    /**
     * @param class-string<ModuleInterface> $module
     */
    public function optionallyRequires(
        string $module,
        string $constraint = '*',
    ): self {
        $this->assertModuleClass($module);
        $this->assertConstraint($constraint);

        return new self(
            $this->name,
            $this->version,
            $this->requiredModules,
            $this->appendDependency(
                $this->optionalModules,
                new ModuleDependency($module, $constraint, false),
            ),
            $this->requiredCapabilities,
            $this->optionalCapabilities,
            $this->providedCapabilities,
            $this->providers,
        );
    }

    public function requiresCapability(string $capability): self
    {
        return new self(
            $this->name,
            $this->version,
            $this->requiredModules,
            $this->optionalModules,
            $this->appendUnique(
                $this->requiredCapabilities,
                $this->validateCapability($capability),
            ),
            $this->optionalCapabilities,
            $this->providedCapabilities,
            $this->providers,
        );
    }

    public function optionallyUses(string $capability): self
    {
        return new self(
            $this->name,
            $this->version,
            $this->requiredModules,
            $this->optionalModules,
            $this->requiredCapabilities,
            $this->appendUnique(
                $this->optionalCapabilities,
                $this->validateCapability($capability),
            ),
            $this->providedCapabilities,
            $this->providers,
        );
    }

    public function provides(string $capability): self
    {
        return new self(
            $this->name,
            $this->version,
            $this->requiredModules,
            $this->optionalModules,
            $this->requiredCapabilities,
            $this->optionalCapabilities,
            $this->appendUnique(
                $this->providedCapabilities,
                $this->validateCapability($capability),
            ),
            $this->providers,
        );
    }

    /**
     * @param class-string<ServiceProviderInterface> $provider
     */
    public function provider(string $provider): self
    {
        if (!is_a($provider, ServiceProviderInterface::class, true)) {
            throw new InvalidModuleException(sprintf(
                'Service provider "%s" must implement %s.',
                $provider,
                ServiceProviderInterface::class,
            ));
        }

        return new self(
            $this->name,
            $this->version,
            $this->requiredModules,
            $this->optionalModules,
            $this->requiredCapabilities,
            $this->optionalCapabilities,
            $this->providedCapabilities,
            $this->appendUnique($this->providers, $provider),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'required_modules' => array_map(
                static fn (ModuleDependency $dependency): array => $dependency->toArray(),
                $this->requiredModules,
            ),
            'optional_modules' => array_map(
                static fn (ModuleDependency $dependency): array => $dependency->toArray(),
                $this->optionalModules,
            ),
            'required_capabilities' => $this->requiredCapabilities,
            'optional_capabilities' => $this->optionalCapabilities,
            'provided_capabilities' => $this->providedCapabilities,
            'providers' => $this->providers,
        ];
    }

    /**
     * @param class-string<ModuleInterface> $module
     */
    private function assertModuleClass(string $module): void
    {
        if (!is_a($module, ModuleInterface::class, true)) {
            throw new InvalidModuleException(sprintf(
                'Module dependency "%s" must implement %s.',
                $module,
                ModuleInterface::class,
            ));
        }
    }

    private function assertConstraint(string $constraint): void
    {
        try {
            (new VersionParser())->parseConstraints($constraint);
        } catch (UnexpectedValueException $exception) {
            throw new InvalidModuleException(sprintf(
                'Module "%s" contains invalid version constraint "%s": %s',
                $this->name,
                $constraint,
                $exception->getMessage(),
            ));
        }
    }

    private function validateCapability(string $capability): string
    {
        $capability = trim($capability);

        if ($capability === '') {
            throw new InvalidModuleException(sprintf(
                'Module "%s" contains an empty capability name.',
                $this->name,
            ));
        }

        return $capability;
    }

    /**
     * @param list<ModuleDependency> $dependencies
     *
     * @return list<ModuleDependency>
     */
    private function appendDependency(
        array $dependencies,
        ModuleDependency $dependency,
    ): array {
        foreach ($dependencies as $existing) {
            if ($existing->module === $dependency->module) {
                throw new InvalidModuleException(sprintf(
                    'Module "%s" declares dependency "%s" more than once.',
                    $this->name,
                    $dependency->module,
                ));
            }
        }

        $dependencies[] = $dependency;

        return $dependencies;
    }

    /**
     * @template T of string
     *
     * @param list<T> $values
     * @param T $value
     *
     * @return list<T>
     */
    private function appendUnique(array $values, string $value): array
    {
        if (!in_array($value, $values, true)) {
            $values[] = $value;
        }

        return $values;
    }
}
