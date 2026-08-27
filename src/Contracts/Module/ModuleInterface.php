<?php

declare(strict_types=1);

namespace Careminate\Contracts\Module;

use Careminate\Module\ModuleContext;
use Careminate\Module\ModuleDefinition;

interface ModuleInterface
{
    public static function definition(): ModuleDefinition;

    public function register(ModuleContext $context): void;

    public function boot(ModuleContext $context): void;
}