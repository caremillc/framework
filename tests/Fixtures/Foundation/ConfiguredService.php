<?php

declare(strict_types=1);

namespace Flint\Tests\Fixtures\Foundation;

use Flint\Configuration\Configuration;

final class ConfiguredService
{
    public function __construct(
        public readonly Configuration $configuration,
    ) {
    }

    public function applicationName(): string
    {
        return $this->configuration->string('app.name');
    }
}
