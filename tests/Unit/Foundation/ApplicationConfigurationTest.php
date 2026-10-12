<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Configuration\Configuration;
use Flint\Container\Exception\ResolutionException;
use Flint\Foundation\Application;
use Flint\Tests\Fixtures\Foundation\ConfiguredService;
use PHPUnit\Framework\TestCase;

final class ApplicationConfigurationTest extends TestCase
{
    public function testTransientServicesReceiveRegisteredConfiguration(): void
    {
        $application = new Application(__DIR__);
        $container = $application->container();

        $configuration = new Configuration([
            'app.name' => 'Flint',
        ]);

        $container->instance(Configuration::class, $configuration);
        $container->autowire(ConfiguredService::class);

        $first = $container->get(ConfiguredService::class);
        $second = $container->get(ConfiguredService::class);

        self::assertInstanceOf(ConfiguredService::class, $first);
        self::assertInstanceOf(ConfiguredService::class, $second);
        self::assertNotSame($first, $second);

        self::assertSame($configuration, $first->configuration);
        self::assertSame($configuration, $second->configuration);
        self::assertSame('Flint', $first->applicationName());
    }

    public function testSingletonServiceRetainsItsIdentity(): void
    {
        $application = new Application(__DIR__);
        $container = $application->container();

        $configuration = new Configuration([
            'app.name' => 'Flint',
        ]);

        $container->instance(Configuration::class, $configuration);
        $container->autowire(ConfiguredService::class, singleton: true);

        $first = $container->get(ConfiguredService::class);
        $second = $container->get(ConfiguredService::class);

        self::assertInstanceOf(ConfiguredService::class, $first);
        self::assertSame($first, $second);
        self::assertSame($configuration, $first->configuration);
    }

    public function testApplicationsInjectTheirOwnConfiguration(): void
    {
        $firstApplication = new Application(__DIR__);
        $secondApplication = new Application(__DIR__);

        $firstConfiguration = new Configuration([
            'app.name' => 'First application',
        ]);

        $secondConfiguration = new Configuration([
            'app.name' => 'Second application',
        ]);

        $firstApplication->container()->instance(
            Configuration::class,
            $firstConfiguration,
        );

        $secondApplication->container()->instance(
            Configuration::class,
            $secondConfiguration,
        );

        $firstApplication->container()->autowire(
            ConfiguredService::class,
            singleton: true,
        );

        $secondApplication->container()->autowire(
            ConfiguredService::class,
            singleton: true,
        );

        $first = $firstApplication->container()->get(
            ConfiguredService::class,
        );

        $second = $secondApplication->container()->get(
            ConfiguredService::class,
        );

        self::assertInstanceOf(ConfiguredService::class, $first);
        self::assertInstanceOf(ConfiguredService::class, $second);
        self::assertNotSame($first, $second);

        self::assertSame($firstConfiguration, $first->configuration);
        self::assertSame($secondConfiguration, $second->configuration);

        self::assertSame('First application', $first->applicationName());
        self::assertSame('Second application', $second->applicationName());
    }

    public function testMissingConfigurationCanBeRegisteredAfterFailure(): void
    {
        $application = new Application(__DIR__);
        $container = $application->container();

        $container->autowire(ConfiguredService::class, singleton: true);

        try {
            $container->get(ConfiguredService::class);

            self::fail('Missing configuration must prevent resolution.');
        } catch (ResolutionException $exception) {
            self::assertStringContainsString(
                'parameter $configuration',
                $exception->getMessage(),
            );
        }

        self::assertFalse($container->has(Configuration::class));

        $configuration = new Configuration([
            'app.name' => 'Recovered application',
        ]);

        $container->instance(Configuration::class, $configuration);

        $service = $container->get(ConfiguredService::class);

        self::assertInstanceOf(ConfiguredService::class, $service);
        self::assertSame($configuration, $service->configuration);
        self::assertSame(
            'Recovered application',
            $service->applicationName(),
        );
    }
}
