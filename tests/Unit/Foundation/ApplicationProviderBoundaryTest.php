<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Container\Container;
use Flint\Container\Exception\ContainerException;
use Flint\Foundation\Application;
use Flint\Foundation\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class ApplicationProviderBoundaryTest extends TestCase
{
    public function testSingletonConstructionIsDeferredUntilResolution(): void
    {
        $application = new Application(__DIR__);

        $provider = new class () implements ServiceProvider {
            public int $constructionCount = 0;

            public function register(Container $container): void
            {
                $container->singleton(
                    'service',
                    function (ContainerInterface $container): stdClass {
                        ++$this->constructionCount;

                        return new stdClass();
                    },
                );
            }
        };

        $application->register($provider);

        self::assertTrue($application->container()->has('service'));
        self::assertSame(0, $provider->constructionCount);

        $first = $application->container()->get('service');
        $second = $application->container()->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $provider->constructionCount);
    }

    public function testReusedProviderDoesNotShareContainerSingletons(): void
    {
        $firstApplication = new Application(__DIR__);
        $secondApplication = new Application(__DIR__);

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
                $container->singleton(
                    'service',
                    static fn (ContainerInterface $container): stdClass =>
                        new stdClass(),
                );
            }
        };

        $firstApplication->register($provider);
        $secondApplication->register($provider);

        $first = $firstApplication->container()->get('service');
        $second = $secondApplication->container()->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
    }

    public function testDuplicateRegistrationPreservesOriginalService(): void
    {
        $application = new Application(__DIR__);

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
                $container->instance('service', new stdClass());
            }
        };

        $application->register($provider);

        $original = $application->container()->get('service');

        self::assertInstanceOf(stdClass::class, $original);

        try {
            $application->register($provider);

            self::fail('Duplicate service registration must fail.');
        } catch (ContainerException $exception) {
            self::assertSame(
                'Container entry "service" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            $original,
            $application->container()->get('service'),
        );
    }
}
