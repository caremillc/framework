<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\ResolutionException;
use Closure;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class DependencyPathTest extends TestCase
{
    public function testExistingConstructorArgumentsRemainSupported(): void
    {
        $previous = new Error('Original failure.');

        $exception = new ResolutionException(
            message: 'Resolution failed.',
            code: 17,
            previous: $previous,
        );

        self::assertSame('Resolution failed.', $exception->getMessage());
        self::assertSame(17, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame([], $exception->dependencyPath());
    }

    public function testDependencyPathIsAnIndependentSnapshot(): void
    {
        $path = ['service', 'dependency'];

        $exception = new ResolutionException(
            dependencyPath: $path,
        );

        $path[] = 'later';

        $returnedPath = $exception->dependencyPath();
        $returnedPath[] = 'another';

        self::assertSame(
            ['service', 'dependency'],
            $exception->dependencyPath(),
        );
    }

    /**
     * @param array<array-key, mixed> $path
     */
    #[DataProvider('invalidPaths')]
    public function testInvalidPathStructuresAreRejected(array $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResolutionException(dependencyPath: $path);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'associative array' => [
            ['entry' => 'service'],
        ];

        yield 'non-string identifier' => [
            ['service', 17],
        ];
    }

    public function testMissingDependencyIncludesTheTerminalLookup(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'missing',
            ),
        );

        $exception = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            ['service', 'missing'],
            $exception->dependencyPath(),
        );

        $previous = $exception->getPrevious();

        self::assertInstanceOf(EntryNotFoundException::class, $previous);

        self::assertSame(
            ['service', 'missing'],
            $previous->dependencyPath(),
        );
    }

    public function testAliasCycleReportsRequestedNames(): void
    {
        $container = new Container();

        $container->factory(
            'internal.a',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'public.b',
            ),
        );

        $container->factory(
            'internal.b',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'public.a',
            ),
        );

        $container->alias('public.a', 'internal.a');
        $container->alias('public.b', 'internal.b');

        $exception = self::captureFailure(
            static fn (): mixed => $container->get('public.a'),
        );

        self::assertSame(
            ['public.a', 'public.b', 'public.a'],
            $exception->dependencyPath(),
        );
    }

    public function testPathStateIsCleanedAfterAnIndirectCycle(): void
    {
        $container = new Container();
        $attempts = 0;

        $container->factory(
            'first',
            static function (ContainerInterface $resolver) use (&$attempts): mixed {
                ++$attempts;

                if ($attempts === 1) {
                    return $resolver->get('second');
                }

                return 'recovered';
            },
        );

        $container->factory(
            'second',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'first',
            ),
        );

        $exception = self::captureFailure(
            static fn (): mixed => $container->get('first'),
        );

        self::assertSame(
            ['first', 'second', 'first'],
            $exception->dependencyPath(),
        );

        self::assertSame('recovered', $container->get('first'));

        $original = new Error('Unrelated failure.');

        $container->factory(
            'unrelated',
            static function (ContainerInterface $resolver) use ($original): never {
                throw $original;
            },
        );

        $unrelated = self::captureFailure(
            static fn (): mixed => $container->get('unrelated'),
        );

        self::assertSame(['unrelated'], $unrelated->dependencyPath());
        self::assertSame($original, $unrelated->getPrevious());

        self::assertSame(
            ['first', 'second', 'first'],
            $exception->dependencyPath(),
        );
    }

    public function testEmptyLookupIdentifierCanAppearInDiagnostics(): void
    {
        $container = new Container();

        try {
            $container->get('');
        } catch (EntryNotFoundException $exception) {
            self::assertSame([''], $exception->dependencyPath());

            return;
        }

        self::fail('An empty lookup must report an unknown entry.');
    }

    public function testIdentifiersAreNotInsertedIntoExceptionMessages(): void
    {
        $container = new Container();
        $identifier = "private.identifier\nwith-control-character";

        $container->factory(
            $identifier,
            static function (ContainerInterface $resolver): never {
                throw new Error('Operation failed.');
            },
        );

        $exception = self::captureFailure(
            static fn (): mixed => $container->get($identifier),
        );

        self::assertSame(
            'The requested entry could not be resolved.',
            $exception->getMessage(),
        );

        self::assertSame([$identifier], $exception->dependencyPath());
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(Closure $operation): ResolutionException
    {
        try {
            $operation();
        } catch (ResolutionException $exception) {
            return $exception;
        }

        self::fail('The operation must throw a resolution exception.');
    }
}
