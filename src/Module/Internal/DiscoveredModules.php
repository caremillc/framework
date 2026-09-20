<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\ModuleBootRegistration;
use Careminate\Module\ModuleRegistration;

/**
 * Enabled module instances ready for application composition.
 *
 * @internal
 */
final readonly class DiscoveredModules
{
    /**
     * @param list<ModuleRegistration> $registrations
     * @param list<ModuleBootRegistration> $bootRegistrations
     */
    public function __construct(
        public array $registrations,
        public array $bootRegistrations,
    ) {
    }
}
