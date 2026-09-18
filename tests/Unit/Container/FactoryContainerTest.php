<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\ResolutionException;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;

final class FactoryContainerTest extends TestCase
{
    public function testTransientFactoryIsLazyAndRunsForEveryLookup(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->factory(
            'service',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        self::assertCount(0, $calls);
        self::assertTrue($container->has('service'));
        self::assertCount(0, $calls);

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
        self::assertCount(2, $calls);
    }

    public function testSingletonIsLazyAndCachesTheSuccessfulResult(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'service',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        self::assertTrue($container->has('service'));
        self::assertCount(0, $calls);

        $first = $container->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertSame($first, $container->get('service'));
        self::assertCount(1, $calls);
    }

    public function testSingletonCachesNull(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'optional',
            static function (ContainerInterface $resolver) use ($calls): mixed {
                $calls->append(true);

                return null;
            },
        );

        self::assertNull($container->get('optional'));
        self::assertTrue($container->has('optional'));
        self::assertNull($container->get('optional'));
        self::assertCount(1, $calls);
    }

    public function testFactoriesCanResolveExplicitDependencies(): void
    {
        $container = new Container();
        $dependency = new stdClass();

        $container->register('dependency', $dependency);

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'dependency',
            ),
        );

        self::assertSame($dependency, $container->get('service'));
    }

    public function testFailedSingletonCanBeRetriedWithoutLosingItsCause(): void
    {
        $container = new Container();
        $original = new Error('Dependency initialization failed.');
        $service = new stdClass();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'service',
            static function (ContainerInterface $resolver) use (
                $calls,
                $original,
                $service,
            ): stdClass {
                $calls->append(true);

                if ($calls->count() === 1) {
                    throw $original;
                }

                return $service;
            },
        );

        $failure = self::captureResolutionFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame($original, $failure->getPrevious());
        self::assertTrue($container->has('service'));
        self::assertSame($service, $container->get('service'));
        self::assertSame($service, $container->get('service'));
        self::assertCount(2, $calls);
    }

    public function testMissingFactoryDependencyIsWrappedAsResolutionFailure(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'missing',
            ),
        );

        self::assertTrue($container->has('service'));

        $failure = self::captureResolutionFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(
            NotFoundExceptionInterface::class,
            $failure->getPrevious(),
        );
    }

    public function testRecursionFailureDoesNotLeaveResolutionStateBehind(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'service',
            static function (ContainerInterface $resolver) use ($calls): mixed {
                $calls->append(true);

                if ($calls->count() === 1) {
                    return $resolver->get('service');
                }

                return 'recovered';
            },
        );

        $failure = self::captureResolutionFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(
            ResolutionException::class,
            $failure->getPrevious(),
        );

        self::assertSame('recovered', $container->get('service'));
        self::assertSame('recovered', $container->get('service'));
        self::assertCount(2, $calls);
    }

    public function testFactoryCannotReplaceRegisteredNull(): void
    {
        $container = new Container();
        $container->register('entry', null);

        try {
            $container->factory(
                'entry',
                static fn (ContainerInterface $resolver): string => 'replacement',
            );
        } catch (DuplicateEntryException) {
            self::assertNull($container->get('entry'));

            return;
        }

        self::fail('Factory registration must reject an existing value.');
    }

    public function testSingletonCannotReplaceAnUnresolvedFactory(): void
    {
        $container = new Container();

        $container->factory(
            'entry',
            static fn (ContainerInterface $resolver): string => 'original',
        );

        try {
            $container->singleton(
                'entry',
                static fn (ContainerInterface $resolver): string => 'replacement',
            );
        } catch (DuplicateEntryException) {
            self::assertSame('original', $container->get('entry'));

            return;
        }

        self::fail('Singleton registration must reject an existing factory.');
    }

    public function testValueCannotReplaceAResolvedSingleton(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->singleton(
            'entry',
            static fn (ContainerInterface $resolver): stdClass => $service,
        );

        self::assertSame($service, $container->get('entry'));

        try {
            $container->register('entry', 'replacement');
        } catch (DuplicateEntryException) {
            self::assertSame($service, $container->get('entry'));

            return;
        }

        self::fail('Value registration must reject a resolved singleton.');
    }

    public function testFactoryRejectsAnEmptyIdentifier(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->factory(
            '',
            static fn (ContainerInterface $resolver): mixed => null,
        );
    }

    public function testSingletonRejectsAnEmptyIdentifier(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->singleton(
            '',
            static fn (ContainerInterface $resolver): mixed => null,
        );
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureResolutionFailure(
        Closure $operation,
    ): ResolutionException {
        try {
            $operation();
        } catch (ResolutionException $exception) {
            return $exception;
        }

        self::fail('The operation must throw a resolution exception.');
    }
}
