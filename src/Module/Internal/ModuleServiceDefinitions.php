<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;

/**
 * Merged definitions with module order, ownership, and explicit exports.
 *
 * @internal
 */
final readonly class ModuleServiceDefinitions
{
    /**
     * @var list<string>
     */
    public array $exportedServices;

    /**
     * @param list<ModuleDefinition> $modules
     * @param array<string, ModuleIdentifier> $owners
     * @param list<string> $exportedServices
     */
    public function __construct(
        public DefinitionSet $definitions,
        public array $modules,
        private array $owners,
        array $exportedServices = [],
    ) {
        $moduleNames = [];

        foreach ($modules as $module) {
            $moduleNames[$module->id->value] = true;
        }

        $serviceNames = [];

        foreach ($definitions->services as $service) {
            $serviceNames['entry:' . $service->id] = true;
        }

        /** @var array<string, string> $exports */
        $exports = [];

        foreach ($exportedServices as $id) {
            $key = 'entry:' . $id;
            $owner = $this->ownerOf($id);

            if (
                !isset($serviceNames[$key])
                || $owner === null
                || !isset($moduleNames[$owner->value])
            ) {
                throw new InvalidModuleBoundaryException(
                    'An export must name a service owned by an enabled module.',
                );
            }

            $exports[$key] = $id;
        }

        $names = array_values($exports);
        sort($names, SORT_STRING);

        $this->exportedServices = $names;
    }

    public function ownerOf(string $serviceId): ?ModuleIdentifier
    {
        return $this->owners['entry:' . $serviceId] ?? null;
    }
}
