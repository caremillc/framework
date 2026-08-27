<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

final class IncompatibleModuleVersionException extends ModuleException
{
    public function __construct(
        string $module,
        string $dependency,
        string $installedVersion,
        string $requiredConstraint,
    ) {
        parent::__construct(sprintf(
            'Module "%s" requires "%s" version "%s"; installed version is "%s".',
            $module,
            $dependency,
            $requiredConstraint,
            $installedVersion,
        ));
    }
}