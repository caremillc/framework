<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Declares a capability supplied by a module.
 *
 * @api
 */
final readonly class CapabilityProvision
{
    public function __construct(
        public CapabilityIdentifier $capability,
        public bool $exclusive = false,
    ) {
    }
}
