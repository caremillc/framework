<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Cache\ModuleCache;
use Careminate\Module\ModulePlan;
use PHPUnit\Framework\TestCase;

final class ModuleCacheTest extends TestCase
{
    public function test_module_plan_round_trip(): void
    {
        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-cache-test-'
            . bin2hex(random_bytes(6));

        $path = $directory . DIRECTORY_SEPARATOR . 'modules.json';

        $plan = new ModulePlan(
            ['App\\Users\\UsersModule'],
            ['billing'],
            hash('sha256', 'test-plan'),
        );

        $cache = new ModuleCache();
        $cache->write($path, $plan);

        $loaded = $cache->load($path);

        self::assertNotNull($loaded);
        self::assertSame($plan->toArray(), $loaded->toArray());

        unlink($path);
        rmdir($directory);
    }
}
