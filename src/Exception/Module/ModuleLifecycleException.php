<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

use Throwable;

final class ModuleLifecycleException extends ModuleException
{
    public function __construct(
        string $module,
        string $phase,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Module "%s" failed during its "%s" lifecycle phase: %s',
                $module,
                $phase,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
