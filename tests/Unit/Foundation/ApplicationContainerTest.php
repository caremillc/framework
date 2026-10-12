<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Configuration\Configuration;
use Flint\Foundation\Application;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ApplicationContainerTest extends TestCase
{
    public function testContainerRetainsRegisteredEntries(): void
    {
        $application = new Application(__DIR__);
        $service = new stdClass();

        $application->container()->instance('service', $service);

        self::assertSame(
            $service,
            $application->container()->get('service'),
        );
    }

    public function testApplicationsHaveIndependentContainers(): void
    {
        $first = new Application(__DIR__);
        $second = new Application(__DIR__);

        $first->container()->instance('service', 'first');

        self::assertNotSame(
            $first->container(),
            $second->container(),
        );
        self::assertFalse($second->container()->has('service'));

        $second->container()->instance('service', 'second');

        self::assertSame(
            'first',
            $first->container()->get('service'),
        );
        self::assertSame(
            'second',
            $second->container()->get('service'),
        );
    }

    public function testConfigurationCanBeRegisteredAndResolved(): void
    {
        $application = new Application(__DIR__);
        $configuration = new Configuration([
            'app.name' => 'Flint',
            'app.debug' => false,
        ]);

        $application->container()->instance(
            Configuration::class,
            $configuration,
        );

        self::assertSame(
            $configuration,
            $application->container()->get(Configuration::class),
        );
    }

    public function testDirectConstructionDoesNotLoadConfiguration(): void
    {
        $application = new Application(__DIR__);

        self::assertFalse(
            $application->container()->has(Configuration::class),
        );
    }
}
