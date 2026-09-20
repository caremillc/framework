<?php

declare(strict_types=1);

namespace Careminate\Module\Exception;

use Careminate\Exception\FrameworkException;
use Careminate\Module\CapabilityIdentifier;
use Careminate\Module\ModuleIdentifier;

/**
 * Reports an unsatisfied or conflicting capability declaration.
 *
 * @api
 */
final class CapabilityResolutionException extends FrameworkException
{
    /**
     * @var list<ModuleIdentifier>
     */
    private readonly array $modules;

    /**
     * @param list<ModuleIdentifier> $modules
     */
    public function __construct(
        string $message,
        public readonly CapabilityIdentifier $capability,
        array $modules,
    ) {
        parent::__construct($message);

        $this->modules = $modules;
    }

    /**
     * @return list<ModuleIdentifier>
     */
    public function modules(): array
    {
        return $this->modules;
    }
}
