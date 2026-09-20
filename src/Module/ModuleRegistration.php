<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Associates module metadata with explicitly supplied providers.
 *
 * @api
 */
final readonly class ModuleRegistration
{
    /**
     * @var list<ServiceProviderInterface>
     */
    public array $providers;

    public function __construct(
        public ModuleDefinition $definition,
        ServiceProviderInterface ...$providers,
    ) {
        $this->providers = array_values($providers);
    }
}
