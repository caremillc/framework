<?php

declare(strict_types=1);

namespace Careminate\Exception\Module;

final class CircularModuleDependencyException extends ModuleException
{
    /**
     * @param list<string> $cycle
     */
    public function __construct(array $cycle)
    {
        parent::__construct(sprintf(
            'Circular module dependency detected: %s.',
            implode(' -> ', $cycle),
        ));
    }
}