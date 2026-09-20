<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\ModuleIdentifier;

/**
 * A module entry-point declaration and its originating package.
 *
 * @internal
 */
final readonly class ComposerModuleEntry
{
    public function __construct(
        public string $package,
        public string $className,
        public ?ModuleIdentifier $moduleId = null,
    ) {
    }
}
