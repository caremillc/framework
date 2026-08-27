<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Contracts\Module\ModuleInterface;

final readonly class ModuleDependency
{
    /**
     * @param class-string<ModuleInterface> $module
     */
    public function __construct(
        public string $module,
        public string $constraint,
        public bool $required,
    ) {
    }

    /**
     * @return array{module: string, constraint: string, required: bool}
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'constraint' => $this->constraint,
            'required' => $this->required,
        ];
    }
}

