<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\ExportingServiceRegistryInterface;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ServiceLifetime;
use LogicException;

/**
 * Records services for one owner and seals registration on completion.
 *
 * @internal
 */
final class OwnedServiceRegistry implements ExportingServiceRegistryInterface
{
    private readonly DefinitionBuilder $builder;

    private ?OwnedServiceDefinitions $snapshot = null;

    /**
     * @var array<string, true>
     */
    private array $registered = [];

    /**
     * @var array<string, string>
     */
    private array $exports = [];

    public function __construct(
        private readonly ModuleIdentifier $owner,
    ) {
        $this->builder = new DefinitionBuilder();
    }

    public function value(string $id, mixed $value): void
    {
        $this->assertOpen();

        $this->builder->add(ServiceDefinition::forValue($id, $value));

        $this->registered['entry:' . $id] = true;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function autowire(
        string $id,
        string $className,
        ServiceLifetime $lifetime = ServiceLifetime::Transient,
        array $arguments = [],
        bool $lazy = false,
    ): void {
        $this->assertOpen();

        $definitionLifetime = match ($lifetime) {
            ServiceLifetime::Transient => DefinitionLifetime::Transient,
            ServiceLifetime::Singleton => DefinitionLifetime::Singleton,
            ServiceLifetime::Scoped => DefinitionLifetime::Scoped,
        };

        $this->builder->add(ServiceDefinition::forAutowire(
            id: $id,
            className: $className,
            lifetime: $definitionLifetime,
            arguments: $arguments,
            lazy: $lazy,
        ));

        $this->registered['entry:' . $id] = true;
    }

    public function export(string $id): void
    {
        $this->assertOpen();

        $key = 'entry:' . $id;

        if (!isset($this->registered[$key])) {
            throw new InvalidModuleBoundaryException(
                'An exported service must already be registered by this module.',
            );
        }

        $this->exports[$key] = $id;
    }

    public function seal(): OwnedServiceDefinitions
    {
        return $this->snapshot ??= new OwnedServiceDefinitions(
            $this->owner,
            $this->builder->build(),
            array_values($this->exports),
        );
    }

    private function assertOpen(): void
    {
        if ($this->snapshot !== null) {
            throw new LogicException(
                'The module service registry is sealed.',
            );
        }
    }
}
