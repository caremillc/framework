<?php

declare(strict_types=1);

namespace CareminateIntegration\Tests\Fixtures\Application;

use Careminate\Application\BootstrapInputs;

final readonly class CompiledBootstrapConsumer
{
    public function __construct(
        public BootstrapInputs $inputs,
    ) {
    }
}
