<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Exception\ScopeStateException;
use Closure;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Throwable;

final class ScopedContainerTest extends TestCase
{
    public function testScopedIdentityIsSharedWithinAScopeAndSeparatedBetweenScopes(): void
    {
        $container = new Container();

        $container->scoped(
            'service',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->alias('alias', 'service');
        $container->tag('services', 'alias');
        $container->freeze();

        $first = $container->runInScope(
            static function (ContainerInterface $resolver) use ($container): mixed {
                $service = $resolver->get('service');

                self::assertSame($service, $resolver->get('alias'));
                self::assertSame([$service], $container->tagged('services'));

                return $service;
            },
        );

        $second = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
        self::assertTrue($container->has('service'));
        self::assertTrue($container->isFrozen());
    }

    public function testScopedNullIsCachedWithoutEagerConstruction(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->scoped(
            'service',
            static function (ContainerInterface $resolver) use ($calls): mixed {
                $calls->append(true);

                return null;
            },
        );

        self::assertCount(0, $calls);

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                self::assertNull($resolver->get('service'));
                self::assertNull($resolver->get('service'));
            },
        );

        self::assertCount(1, $calls);

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                self::assertNull($resolver->get('service'));
            },
        );

        self::assertCount(2, $calls);
    }

    public function testScopedLookupOutsideAScopeFails(): void
    {
        $container = new Container();

        $container->scoped(
            'service',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        self::assertTrue($container->has('service'));

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ScopeStateException::class, $failure);
        self::assertSame(['service'], $failure->dependencyPath());
    }

    public function testCallbackFailureClosesTheScopeAndPreservesTheException(): void
    {
        $container = new Container();
        $original = new Error('Operation failed.');

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->scoped(
            'service',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->runInScope(
                static function (ContainerInterface $resolver) use ($original): never {
                    $resolver->get('service');

                    throw $original;
                },
            ),
        );

        self::assertSame($original, $failure);

        $outside = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ScopeStateException::class, $outside);

        $service = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(stdClass::class, $service);
        self::assertCount(2, $calls);
    }

    public function testNestedScopeRejectionDoesNotClearTheOuterCache(): void
    {
        $container = new Container();

        $container->scoped(
            'service',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($container): void {
                $service = $resolver->get('service');

                $failure = self::captureFailure(
                    static fn (): mixed => $container->runInScope(
                        static fn (ContainerInterface $nested): string => 'nested',
                    ),
                );

                self::assertInstanceOf(ScopeStateException::class, $failure);
                self::assertSame($service, $resolver->get('service'));
            },
        );
    }

    public function testScopeCannotBeginDuringServiceResolution(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): mixed => $container->runInScope(
                static fn (ContainerInterface $scoped): string => 'invalid',
            ),
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(ScopeStateException::class, $failure->getPrevious());

        self::assertSame(
            'healthy',
            $container->runInScope(
                static fn (ContainerInterface $resolver): string => 'healthy',
            ),
        );
    }

    public function testFailedScopedConstructionCanRetryInTheSameScope(): void
    {
        $container = new Container();
        $original = new Error('First attempt failed.');

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->scoped(
            'service',
            static function (ContainerInterface $resolver) use (
                $calls,
                $original,
            ): stdClass {
                $calls->append(true);

                if ($calls->count() === 1) {
                    throw $original;
                }

                return new stdClass();
            },
        );

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($original): void {
                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('service'),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertSame($original, $failure->getPrevious());

                $service = $resolver->get('service');

                self::assertInstanceOf(stdClass::class, $service);
                self::assertSame($service, $resolver->get('service'));
            },
        );

        self::assertCount(2, $calls);
    }

    public function testScopedServiceCanDependOnSingletonAndScopedServices(): void
    {
        $container = new Container();

        $container->singleton(
            'shared',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->scoped(
            'dependency',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->scoped(
            'owner',
            static fn (ContainerInterface $resolver): array => [
                $resolver->get('shared'),
                $resolver->get('dependency'),
            ],
        );

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                self::assertSame(
                    [$resolver->get('shared'), $resolver->get('dependency')],
                    $resolver->get('owner'),
                );
            },
        );

        $shared = $container->get('shared');

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($shared): void {
                self::assertSame($shared, $resolver->get('shared'));
            },
        );
    }

    #[DataProvider('singletonRoutes')]
    public function testSingletonCannotAcquireScopedState(
        string $route,
        bool $warmCache,
    ): void {
        $container = new Container();

        $container->scoped(
            'scoped',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->alias('scoped.alias', 'scoped');
        $container->tag('group', 'scoped.alias');

        $container->factory(
            'bridge',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'scoped',
            ),
        );

        $container->singleton(
            'singleton',
            static fn (ContainerInterface $resolver): mixed => match ($route) {
                'transient' => $resolver->get('bridge'),
                'alias' => $resolver->get('scoped.alias'),
                'tagged' => $container->tagged('group'),
                default => $resolver->get('scoped'),
            },
        );

        $container->runInScope(
            static function (ContainerInterface $resolver) use (
                $route,
                $warmCache,
            ): void {
                if ($warmCache) {
                    $resolver->get('scoped');
                }

                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('singleton'),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);

                $expectedPath = match ($route) {
                    'transient' => ['singleton', 'bridge', 'scoped'],
                    'alias', 'tagged' => ['singleton', 'scoped.alias'],
                    default => ['singleton', 'scoped'],
                };

                self::assertSame($expectedPath, $failure->dependencyPath());

                $cause = $failure;

                while (($previous = $cause->getPrevious()) !== null) {
                    $cause = $previous;
                }

                self::assertInstanceOf(LifetimeViolationException::class, $cause);

                $service = $resolver->get('scoped');

                self::assertInstanceOf(stdClass::class, $service);
                self::assertSame($service, $resolver->get('scoped'));
            },
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function singletonRoutes(): iterable
    {
        foreach (['direct', 'transient', 'alias', 'tagged'] as $route) {
            yield $route . ' cold' => [$route, false];
            yield $route . ' cached' => [$route, true];
        }
    }

    public function testScopedCyclesPreservePathsAndAllowSubsequentResolution(): void
    {
        $container = new Container();

        $container->scoped(
            'cycle',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'cycle',
            ),
        );

        $container->scoped(
            'healthy',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('cycle'),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertSame(['cycle', 'cycle'], $failure->dependencyPath());
                self::assertInstanceOf(stdClass::class, $resolver->get('healthy'));
            },
        );
    }

    public function testScopedRegistrationRespectsFreeze(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->scoped(
            'service',
            static fn (ContainerInterface $resolver): mixed => null,
        );
    }

    public function testScopedRegistrationRejectsAnEmptyIdentifier(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->scoped(
            '',
            static fn (ContainerInterface $resolver): mixed => null,
        );
    }

    public function testScopedRegistrationPreservesAnExistingEntry(): void
    {
        $container = new Container();
        $container->register('service', null);

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->scoped(
                    'service',
                    static fn (ContainerInterface $resolver): string => 'replacement',
                );
            },
        );

        self::assertInstanceOf(DuplicateEntryException::class, $failure);
        self::assertNull($container->get('service'));
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(Closure $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The operation must throw an exception.');
    }
}
