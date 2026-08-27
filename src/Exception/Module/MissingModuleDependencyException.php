<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

final class MissingModuleDependencyException extends ModuleException
{
    public function __construct(string $module, string $dependency)
    {
        parent::__construct(sprintf(
            'Module "%s" requires missing module "%s".',
            $module,
            $dependency,
        ));
    }
}