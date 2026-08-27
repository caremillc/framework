<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

final class MissingCapabilityException extends ModuleException
{
    public function __construct(string $module, string $capability)
    {
        parent::__construct(sprintf(
            'Module "%s" requires capability "%s", but no enabled module provides it.',
            $module,
            $capability,
        ));
    }
}