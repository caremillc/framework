<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Exception\Container\CircularDependencyException;
use Careminate\Exception\Container\InvalidDefinitionException;
use Careminate\Exception\Container\NotFoundException;
use Careminate\Exception\Container\UnresolvableDependencyException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

interface MissingService
{
}

final class PrimitiveFailure
{
    public function __construct(public readonly string $dsn)
    {
    }
}

final class CircularA
{
    public function __construct(public readonly CircularB $dependency)
    {
    }
}

final class CircularB
{
    public function __construct(public readonly CircularA $dependency)
    {
    }
}

final class FailureTest extends TestCase
{
    public function testMissingEntryThrowsPsrNotFoundException(): void
    {
        $container = new Container();

        try {
            $container->get(MissingService::class);
            self::fail('Expected missing container entry exception.');
        } catch (NotFoundException $exception) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception);
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
            self::assertSame([MissingService::class], $exception->resolutionPath());
        }
    }

    public function testPrimitiveWithoutBindingFailsActionably(): void
    {
        $container = new Container();

        $this->expectException(UnresolvableDependencyException::class);
        $this->expectExceptionMessage('parameter "$dsn"');

        $container->get(PrimitiveFailure::class);
    }

    public function testCircularDependencyContainsResolutionPath(): void
    {
        $container = new Container();

        try {
            $container->get(CircularA::class);
            self::fail('Expected a circular dependency exception.');
        } catch (CircularDependencyException $exception) {
            self::assertSame(
                [CircularA::class, CircularB::class, CircularA::class],
                $exception->resolutionPath(),
            );
        }
    }

    public function testDuplicateBindingIsRejected(): void
    {
        $container = new Container();
        $container->bind(CircularA::class);

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('already registered');

        $container->bind(CircularA::class);
    }

    public function testIntentionalReplacementUsesExplicitApi(): void
    {
        $container = new Container();

        $container->instance('value', 'first');
        $container->replace('value', static fn (): string => 'second');

        self::assertSame('second', $container->get('value'));
    }

    public function testFactoryFailurePreservesPreviousException(): void
    {
        $container = new Container();

        $container->factory(
            'failing',
            static function (): never {
                throw new RuntimeException('Factory failed.');
            },
        );

        try {
            $container->get('failing');
            self::fail('Expected resolution failure.');
        } catch (UnresolvableDependencyException $exception) {
            self::assertInstanceOf(
                RuntimeException::class,
                $exception->getPrevious(),
            );

            self::assertSame(
                'Factory failed.',
                $exception->getPrevious()?->getMessage(),
            );
        }
    }
}
