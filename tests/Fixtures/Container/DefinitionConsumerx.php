<?php

declare(strict_types=1);

namespace CareminateIntegration\Tests\Fixtures\Container;

final class DefinitionConsumer
{
    /**
     * @param list<mixed> $handlers
     */
    public function __construct(
        public readonly DefinitionDependency $dependency,
        public readonly array $handlers = [],
        public readonly string $label = 'default',
    ) {
    }
}
