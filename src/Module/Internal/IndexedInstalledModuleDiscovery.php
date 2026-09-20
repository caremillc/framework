<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\ModuleDiscoveryException;
use Careminate\Module\ModuleIdentifier;

/**
 * Discovers indexed modules from explicitly selected installed packages.
 *
 * @internal
 */
final readonly class IndexedInstalledModuleDiscovery
{
    public function __construct(
        private InstalledModuleManifestLoader $manifests =
            new InstalledModuleManifestLoader(),
    ) {
    }

    /**
     * @param iterable<string> $packages
     * @param iterable<ModuleIdentifier> $disabled
     */
    public function discover(
        iterable $packages,
        iterable $disabled = [],
    ): DiscoveredModules {
        $classesByModule = [];

        foreach ($this->manifests->loadIndexed($packages) as $entry) {
            $identifier = $entry->moduleId;

            if ($identifier === null) {
                throw new ModuleDiscoveryException(
                    'Indexed installed discovery requires module identifiers.',
                );
            }

            $classesByModule[$identifier->value] = $entry->className;
        }

        return new ExplicitModuleDiscovery()->discoverIndexed(
            $classesByModule,
            $disabled,
        );
    }
}
