<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Contracts\Module\ModuleInterface;

final readonly class RegisteredModule
{
    /**
     * @param class-string<ModuleInterface> $class
     */
    public function __construct(
        public string $class,
        public ModuleDefinition $definition,
        public ?ModuleInterface $instance = null,
    ) {
    }
}