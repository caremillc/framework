<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Container;

use DateTimeImmutable;
use DateTimeInterface;
use Flint\Container\Container;
use Flint\Container\Exception\ContainerException;
use Flint\Container\Exception\ResolutionException;
use Flint\Tests\Fixtures\Container\Autowiring\ObjectConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\OptionalConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\RequiredNullable;
use Flint\Tests\Fixtures\Container\Autowiring\RequiredScalar;
use Flint\Tests\Fixtures\Container\Autowiring\UnionConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\VariadicConsumer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use TypeError;

final class AutowiringTest extends TestCase
{
    public function testDependenciesCanBeRegisteredAfterTheirConsumer(): void
    {
        $container = new Container();

        $container->autowire(ObjectConsumer::class);
        $container->autowire(stdClass::class, singleton: true);

        $first = $container->get(ObjectConsumer::class);
        $second = $container->get(ObjectConsumer::class);

        self::assertInstanceOf(ObjectConsumer::class, $first);
        self::assertInstanceOf(ObjectConsumer::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame($first->dependency, $second->dependency);
        self::assertSame(
            $container->get(stdClass::class),
            $first->dependency,
        );
    }

    public function testAutowiringSupportsSingletonLifetime(): void
    {
        $container = new Container();

        $container->autowire(stdClass::class);
        $container->autowire(ObjectConsumer::class, singleton: true);

        $first = $container->get(ObjectConsumer::class);

        self::assertInstanceOf(ObjectConsumer::class, $first);
        self::assertSame($first, $container->get(ObjectConsumer::class));
    }

    public function testDefaultsAreUsedWhenDependenciesAreAbsent(): void
    {
        $container = new Container();

        $container->autowire(OptionalConsumer::class);

        $consumer = $container->get(OptionalConsumer::class);

        self::assertInstanceOf(OptionalConsumer::class, $consumer);
        self::assertNull($consumer->clock);
        self::assertSame(3, $consumer->attempts);
    }

    public function testInterfaceAliasTakesPrecedenceOverDefault(): void
    {
        $container = new Container();
        $clock = new DateTimeImmutable('2026-10-10T00:00:00+00:00');

        $container->instance(DateTimeImmutable::class, $clock);
        $container->alias(
            DateTimeInterface::class,
            DateTimeImmutable::class,
        );
        $container->autowire(OptionalConsumer::class);

        $consumer = $container->get(OptionalConsumer::class);

        self::assertInstanceOf(OptionalConsumer::class, $consumer);
        self::assertSame($clock, $consumer->clock);
    }

    public function testFailedRegisteredDependencyDoesNotUseDefault(): void
    {
        $container = new Container();
        $failure = new RuntimeException('Dependency failed.');

        $container->bind(
            DateTimeInterface::class,
            static function (ContainerInterface $container) use ($failure): never {
                throw $failure;
            },
        );

        $container->autowire(OptionalConsumer::class);

        try {
            $container->get(OptionalConsumer::class);

            self::fail('A registered dependency failure must propagate.');
        } catch (ResolutionException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testRequiredScalarNeedsAnExplicitFactory(): void
    {
        $container = new Container();

        $container->autowire(RequiredScalar::class);

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('parameter $name');

        $container->get(RequiredScalar::class);
    }

    public function testNullableWithoutDefaultStillRequiresRegistration(): void
    {
        $container = new Container();

        $container->autowire(RequiredNullable::class);

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage('parameter $clock');

        $container->get(RequiredNullable::class);
    }

    public function testExplicitNullCanSatisfyANullableParameter(): void
    {
        $container = new Container();

        $container->instance(DateTimeInterface::class, null);
        $container->autowire(RequiredNullable::class);

        $consumer = $container->get(RequiredNullable::class);

        self::assertInstanceOf(RequiredNullable::class, $consumer);
        self::assertNull($consumer->clock);
    }

    public function testMissingDependencyDoesNotPoisonASingleton(): void
    {
        $container = new Container();

        $container->autowire(ObjectConsumer::class, singleton: true);

        try {
            $container->get(ObjectConsumer::class);

            self::fail('An unregistered dependency should fail.');
        } catch (ResolutionException $exception) {
            self::assertStringContainsString(
                'parameter $dependency',
                $exception->getMessage(),
            );
        }

        self::assertFalse($container->has(stdClass::class));

        $dependency = new stdClass();

        $container->instance(stdClass::class, $dependency);

        $consumer = $container->get(ObjectConsumer::class);

        self::assertInstanceOf(ObjectConsumer::class, $consumer);
        self::assertSame($dependency, $consumer->dependency);
        self::assertSame($consumer, $container->get(ObjectConsumer::class));
    }

    public function testIncorrectDependencyTypePreservesTheTypeError(): void
    {
        $container = new Container();

        $container->instance(stdClass::class, 'incorrect type');
        $container->autowire(ObjectConsumer::class);

        try {
            $container->get(ObjectConsumer::class);

            self::fail('An incompatible dependency should fail.');
        } catch (ResolutionException $exception) {
            self::assertInstanceOf(
                TypeError::class,
                $exception->getPrevious(),
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function unsupportedClasses(): iterable
    {
        yield 'interface' => [DateTimeInterface::class];
        yield 'union parameter' => [UnionConsumer::class];
        yield 'variadic parameter' => [VariadicConsumer::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unsupportedClasses')]
    public function testUnsupportedClassIsNotRegistered(string $class): void
    {
        $container = new Container();

        try {
            $container->autowire($class);

            self::fail('Unsupported autowiring should fail registration.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(
                'Cannot autowire',
                $exception->getMessage(),
            );
        }

        self::assertFalse($container->has($class));
    }
}
