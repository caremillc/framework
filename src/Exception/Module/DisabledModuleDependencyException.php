<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

final class DisabledModuleDependencyException extends ModuleException
{
    public function __construct(string $module, string $dependency)
    {
        parent::__construct(sprintf(
            'Module "%s" requires module "%s", but that module is disabled.',
            $module,
            $dependency,
        ));
    }
}