<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\ModuleIdentifier;

/**
 * Immutable definitions contributed through one module-owned registry.
 *
 * @internal
 */
final readonly class OwnedServiceDefinitions
{
    /**
     * @var list<string>
     */
    public array $exportedServices;

    /**
     * @param list<string> $exportedServices
     */
    public function __construct(
        public ModuleIdentifier $owner,
        public DefinitionSet $definitions,
        array $exportedServices = [],
    ) {
        $registered = [];

        foreach ($definitions->services as $service) {
            $registered['entry:' . $service->id] = true;
        }

        /** @var array<string, string> $exports */
        $exports = [];

        foreach ($exportedServices as $id) {
            $key = 'entry:' . $id;

            if (!isset($registered[$key])) {
                throw new InvalidModuleBoundaryException(
                    'An exported service must belong to the owned definitions.',
                );
            }

            $exports[$key] = $id;
        }

        $names = array_values($exports);
        sort($names, SORT_STRING);

        $this->exportedServices = $names;
    }
}
