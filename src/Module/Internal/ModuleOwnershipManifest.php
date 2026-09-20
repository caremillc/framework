<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\ModuleIdentifier;

/**
 * Validated ownership metadata, independent of service implementations.
 *
 * @internal
 */
final readonly class ModuleOwnershipManifest
{
    /**
     * @var list<ModuleIdentifier>
     */
    public array $modules;

    /**
     * @var list<array{id: string, owner: ModuleIdentifier}>
     */
    public array $services;

    /**
     * @var array<string, ModuleIdentifier>
     */
    private array $owners;

    /**
     * @param list<ModuleIdentifier> $modules
     * @param list<array{id: string, owner: ModuleIdentifier}> $services
     */
    public function __construct(array $modules, array $services)
    {
        $known = [];

        foreach ($modules as $module) {
            if (isset($known[$module->value])) {
                throw new ModuleCacheException(
                    'An ownership manifest contains a duplicate module.',
                );
            }

            $known[$module->value] = true;
        }

        $owners = [];

        foreach ($services as $service) {
            if ($service['id'] === '') {
                throw new ModuleCacheException(
                    'An owned service identifier must not be empty.',
                );
            }

            if (!isset($known[$service['owner']->value])) {
                throw new ModuleCacheException(
                    'An owned service references an unknown module.',
                );
            }

            $key = 'entry:' . $service['id'];

            if (isset($owners[$key])) {
                throw new ModuleCacheException(
                    'A service has more than one ownership declaration.',
                );
            }

            $owners[$key] = $service['owner'];
        }

        usort(
            $modules,
            static fn (ModuleIdentifier $first, ModuleIdentifier $second): int =>
                strcmp($first->value, $second->value),
        );

        usort(
            $services,
            static fn (array $first, array $second): int =>
                strcmp($first['id'], $second['id']),
        );

        $this->modules = $modules;
        $this->services = $services;
        $this->owners = $owners;
    }

    public static function fromDefinitions(
        ModuleServiceDefinitions $definitions,
    ): self {
        $modules = [];
        $services = [];

        foreach ($definitions->modules as $module) {
            $modules[] = $module->id;
        }

        foreach ($definitions->definitions->services as $service) {
            $owner = $definitions->ownerOf($service->id);

            if ($owner === null) {
                throw new ModuleCacheException(
                    'A contributed service has no recorded owner.',
                );
            }

            $services[] = ['id' => $service->id, 'owner' => $owner];
        }

        return new self($modules, $services);
    }

    public function ownerOf(string $serviceId): ?ModuleIdentifier
    {
        return $this->owners['entry:' . $serviceId] ?? null;
    }
}
