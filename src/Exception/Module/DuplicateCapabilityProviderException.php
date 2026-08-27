<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

final class DuplicateCapabilityProviderException extends ModuleException
{
    public function __construct(
        string $capability,
        string $firstModule,
        string $secondModule,
    ) {
        parent::__construct(sprintf(
            'Capability "%s" is provided by both "%s" and "%s". '
            . 'A capability may have only one owning module.',
            $capability,
            $firstModule,
            $secondModule,
        ));
    }
}