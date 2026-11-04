<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Config\Configuration;
use Careminate\Container\Container;
use Careminate\Foundation\Application;
use Careminate\Foundation\ConfiguredProviderLoader;
use Careminate\Foundation\ServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ConfiguredProviderLoaderTest extends TestCase
{
    public function testEmptyProviderListIsAccepted(): void
    {
        $application = new Application();

        $this->load($application, []);

        $application->boot();

        self::assertTrue($application->isBooted());
    }

    public function testConfiguredProvidersRegisterAndBoot(): void
    {
        ConfiguredProviderFixture::reset();

        $application = new Application();

        $this->load(
            $application,
            [ConfiguredProviderFixture::class]
        );

        $application->boot();

        self::assertSame(
            ['register', 'boot'],
            ConfiguredProviderFixture::$events
        );

        self::assertTrue($application->isBooted());
    }

    public function testProviderReceivesApplicationContainer(): void
    {
        ConfiguredProviderFixture::reset();

        $application = new Application();

        $service = new stdClass();

        $application->container()->instance(
            'configured.service',
            $service
        );

        $this->load(
            $application,
            [ConfiguredProviderFixture::class]
        );

        $application->boot();

        self::assertSame(
            $service,
            ConfiguredProviderFixture::$resolvedService
        );
    }

    public function testDuplicateProviderClassesAreRejected(): void
    {
        $application = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is duplicated');

        $this->load(
            $application,
            [
                ConfiguredProviderFixture::class,
                ConfiguredProviderFixture::class,
            ]
        );
    }

    public function testMissingProviderClassIsRejected(): void
    {
        $application = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->load(
            $application,
            ['Careminate\\Tests\\MissingProvider']
        );
    }

    public function testClassWithoutProviderContractIsRejected(): void
    {
        $application = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must implement ServiceProvider'
        );

        $this->load($application, [stdClass::class]);
    }

    public function testProviderWithRequiredConstructorArgumentIsRejected(): void
    {
        $application = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must have a public constructor'
        );

        $this->load(
            $application,
            [RequiredArgumentProviderFixture::class]
        );
    }

    public function testNonListConfigurationIsRejected(): void
    {
        $application = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list');

        $this->load(
            $application,
            ['primary' => ConfiguredProviderFixture::class]
        );
    }

    public function testNonStringProviderIsRejected(): void
    {
        $application = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must be a non-empty class name'
        );

        $this->load($application, [123]);
    }

    public function testMissingProviderConfigurationIsRejected(): void
    {
        $application = new Application();

        $configuration = new Configuration([]);

        $this->expectException(InvalidArgumentException::class);

        new ConfiguredProviderLoader()->register(
            $application,
            $configuration
        );
    }

    public function testInvalidListDoesNotRegisterEarlierProviders(): void
    {
        ConfiguredProviderFixture::reset();

        $application = new Application();

        try {
            $this->load(
                $application,
                [
                    ConfiguredProviderFixture::class,
                    stdClass::class,
                ]
            );

            self::fail('Expected invalid provider configuration.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'must implement ServiceProvider',
                $exception->getMessage()
            );
        }

        $application->boot();

        self::assertSame(
            [],
            ConfiguredProviderFixture::$events
        );
    }

    /**
     * @param array<array-key, mixed> $classes
     */
    private function load(
        Application $application,
        array $classes
    ): void {
        $configuration = new Configuration([
            'providers' => [
                'classes' => $classes,
            ],
        ]);

        new ConfiguredProviderLoader()->register(
            $application,
            $configuration
        );
    }
}

final class ConfiguredProviderFixture implements ServiceProvider
{
    /** @var list<string> */
    public static array $events = [];

    public static ?object $resolvedService = null;

    public static function reset(): void
    {
        self::$events = [];
        self::$resolvedService = null;
    }

    public function register(Container $container): void
    {
        self::$events[] = 'register';

        if ($container->has('configured.service')) {
            self::$resolvedService = $container->get(
                'configured.service'
            );
        }
    }

    public function boot(Container $container): void
    {
        self::$events[] = 'boot';
    }
}


final class RequiredArgumentProviderFixture implements ServiceProvider
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function register(Container $container): void
    {
        if ($this->value === '') {
            throw new \LogicException(
                'Provider value must not be empty.'
            );
        }
    }

    public function boot(Container $container): void
    {
    }
}
