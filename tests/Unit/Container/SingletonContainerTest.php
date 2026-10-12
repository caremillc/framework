<?php

declare (strict_types=1);

namespace Flint\Tests\Unit\Container;

use Flint\Container\Container;
use Flint\Container\Exception\ResolutionException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

final class SingletonContainerTest extends TestCase
{
    public function testConstructionIsDeferredAndIdentityIsPreserved(): void
    {
        $container = new Container();
        $calls     = 0;

        $container->singleton(
            'service',
            static function (ContainerInterface $container) use (&$calls): object {
                ++$calls;

                return new stdClass();
            },
        );

        self::assertTrue($container->has('service'));
        self::assertSame(0, $calls);

        $first  = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function testNullIsCachedAsASuccessfulResult(): void
    {
        $container = new Container();
        $calls     = 0;

        $container->singleton(
            'nullable',
            static function (ContainerInterface $container) use (&$calls): null {
                ++$calls;

                return null;
            },
        );

        self::assertNull($container->get('nullable'));
        self::assertNull($container->get('nullable'));
        self::assertTrue($container->has('nullable'));
        self::assertSame(1, $calls);
    }

    public function testFailedSingletonIsRetriedAndThenCached(): void
    {
        $container = new Container();
        $failure   = new RuntimeException('First attempt failed.');
        $attempts  = 0;

        $container->singleton(
            'service',
            static function (ContainerInterface $container) use (
                &$attempts,
                $failure,
            ): object {
                ++$attempts;

                if ($attempts === 1) {
                    throw $failure;
                }

                return new stdClass();
            },
        );

        try {
            $container->get('service');

            self::fail('The first resolution should fail.');
        } catch (ResolutionException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertTrue($container->has('service'));

        $resolved = $container->get('service');

        self::assertInstanceOf(stdClass::class, $resolved);
        self::assertSame($resolved, $container->get('service'));
        self::assertSame(2, $attempts);
    }

    public function testContainersDoNotShareSingletonCaches(): void
    {
        $first  = new Container();
        $second = new Container();

        $factory = static fn (ContainerInterface $container): object =>
        new stdClass();

        $first->singleton('service', $factory);
        $second->singleton('service', $factory);

        $firstService  = $first->get('service');
        $secondService = $second->get('service');

        self::assertNotSame($firstService, $secondService);
        self::assertSame($firstService, $first->get('service'));
        self::assertSame($secondService, $second->get('service'));
    }

    public function testSuccessfulDependencySurvivesParentFailure(): void
    {
        $container       = new Container();
        $dependencyCalls = 0;
        $parentCalls     = 0;

        $container->singleton(
            'dependency',
            static function (ContainerInterface $container) use (
                &$dependencyCalls,
            ): object {
                ++$dependencyCalls;

                return new stdClass();
            },
        );

        $container->singleton(
            'parent',
            static function (ContainerInterface $container) use (
                &$parentCalls,
            ): mixed {
                $dependency = $container->get('dependency');

                ++$parentCalls;

                if ($parentCalls === 1) {
                    throw new RuntimeException('Parent construction failed.');
                }

                return $dependency;
            },
        );

        try {
            $container->get('parent');

            self::fail('The first parent resolution should fail.');
        } catch (ResolutionException $exception) {
            self::assertInstanceOf(
                RuntimeException::class,
                $exception->getPrevious(),
            );
        }

        $dependency = $container->get('dependency');

        self::assertSame($dependency, $container->get('parent'));
        self::assertSame(1, $dependencyCalls);
        self::assertSame(2, $parentCalls);
        self::assertSame(1, $dependencyCalls);
        self::assertSame(2, $parentCalls);
    }

    public function testCircularSingletonFailureDoesNotLeaveStaleState(): void
    {
        $container = new Container();

        $container->singleton(
            'recursive',
            static fn (ContainerInterface $container): mixed =>
            $container->get('recursive'),
        );

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $container->get('recursive');

                self::fail('Recursive singleton resolution should fail.');
            } catch (ResolutionException $exception) {
                self::assertSame(
                    'Circular dependency detected: recursive -> recursive',
                    $exception->getMessage(),
                );
            }
        }

        $container->instance('healthy', true);

        self::assertTrue($container->get('healthy'));
    }
}
