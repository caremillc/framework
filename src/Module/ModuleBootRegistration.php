<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Application\BootstrapperInterface;

/**
 * Associates application bootstrappers with their owning module.
 *
 * @api
 */
final readonly class ModuleBootRegistration
{
    /**
     * @var list<BootstrapperInterface>
     */
    public array $bootstrappers;

    public function __construct(
        public ModuleIdentifier $owner,
        BootstrapperInterface ...$bootstrappers,
    ) {
        $this->bootstrappers = array_values($bootstrappers);
    }
}
