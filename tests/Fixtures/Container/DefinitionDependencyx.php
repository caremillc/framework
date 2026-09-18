<?php

declare(strict_types=1);

namespace CareminateIntegration\Tests\Fixtures\Container;

final class DefinitionDependency
{
    public function __construct(
        public readonly string $label = 'default',
    ) {
    }
}
