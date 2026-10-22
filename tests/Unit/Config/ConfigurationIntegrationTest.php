<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Config;

use Careminate\Config\Configuration;
use Careminate\Config\ConfigurationValidator;
use Careminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class ConfigurationIntegrationTest extends TestCase
{
    public function testConfigurationCanBeRegisteredInApplicationContainer(): void
    {
        $application = new Application();

        $configuration = new Configuration([
            'app' => ['name' => 'Careminate'],
        ]);

        $application->container()->instance(
            Configuration::class,
            $configuration
        );

        self::assertSame(
            $configuration,
            $application->container()->get(Configuration::class)
        );
    }

    public function testConfigurationIsAvailableDuringBoot(): void
    {
        $application = new Application();

        $configuration = new Configuration([
            'app' => [
                'name' => 'Careminate',
                'debug' => false,
            ],
        ]);

        new ConfigurationValidator()->validate(
            $configuration,
            [
                'app.name' => 'non-empty-string',
                'app.debug' => 'boolean',
            ]
        );

        $application->container()->instance(
            Configuration::class,
            $configuration
        );

        $resolvedName = null;

        $application->onBoot(
            static function (Application $app) use (&$resolvedName): void {
                $config = $app->container()->get(Configuration::class);

                self::assertInstanceOf(Configuration::class, $config);

                $resolvedName = $config->string('app.name');
            }
        );

        $application->boot();

        self::assertSame('Careminate', $resolvedName);
        self::assertTrue($application->isBooted());
    }
}
