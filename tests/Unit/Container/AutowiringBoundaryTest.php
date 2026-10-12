<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Container;

use Flint\Container\Container;
use Flint\Container\Exception\ContainerException;
use Flint\Container\Exception\ResolutionException;
use Flint\Tests\Fixtures\Container\Autowiring\AbstractConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\ChildConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\CycleFirst;
use Flint\Tests\Fixtures\Container\Autowiring\CycleSecond;
use Flint\Tests\Fixtures\Container\Autowiring\IntersectionConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\ObjectConsumer;
use Flint\Tests\Fixtures\Container\Autowiring\PrivateConstructor;
use Flint\Tests\Fixtures\Container\Autowiring\ReferenceConsumer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class AutowiringBoundaryTest extends TestCase
{
    public function testInheritedConstructorReceivesItsDependency(): void
    {
        $container = new Container();
        $dependency = new stdClass();

        $container->instance(stdClass::class, $dependency);
        $container->autowire(ChildConsumer::class);

        $consumer = $container->get(ChildConsumer::class);

        self::assertInstanceOf(ChildConsumer::class, $consumer);
        self::assertSame($dependency, $consumer->dependency);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function unsupportedClasses(): iterable
    {
        yield 'intersection' => [IntersectionConsumer::class];
        yield 'reference' => [ReferenceConsumer::class];
        yield 'private constructor' => [PrivateConstructor::class];
        yield 'abstract class' => [AbstractConsumer::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unsupportedClasses')]
    public function testRejectedAutowiringLeavesNoRegistration(
        string $class,
    ): void {
        $container = new Container();

        try {
            $container->autowire($class, singleton: true);

            self::fail('Unsupported autowiring must fail registration.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(
                'Cannot autowire',
                $exception->getMessage(),
            );
        }

        self::assertFalse($container->has($class));

        $replacement = new stdClass();

        $container->instance($class, $replacement);

        self::assertSame($replacement, $container->get($class));
    }

    public function testPrivateConstructorCanUseAnExplicitFactory(): void
    {
        $container = new Container();

        $container->bind(
            PrivateConstructor::class,
            static fn (ContainerInterface $container): PrivateConstructor =>
                PrivateConstructor::create(),
        );

        self::assertInstanceOf(
            PrivateConstructor::class,
            $container->get(PrivateConstructor::class),
        );
    }

    public function testAutowiredCycleReportsItsPathAndCleansUp(): void
    {
        $container = new Container();

        $container->autowire(CycleFirst::class, singleton: true);
        $container->autowire(CycleSecond::class, singleton: true);

        $expectedMessage = sprintf(
            'Circular dependency detected: %s -> %s -> %s',
            CycleFirst::class,
            CycleSecond::class,
            CycleFirst::class,
        );

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $container->get(CycleFirst::class);

                self::fail('The constructor cycle must fail resolution.');
            } catch (ResolutionException $exception) {
                self::assertSame(
                    $expectedMessage,
                    $exception->getMessage(),
                );
            }
        }

        $container->autowire(stdClass::class);

        self::assertInstanceOf(
            stdClass::class,
            $container->get(stdClass::class),
        );
    }

    public function testAliasInsideAnAutowiredCycleIsReported(): void
    {
        $container = new Container();

        $container->autowire(ObjectConsumer::class);
        $container->alias(stdClass::class, ObjectConsumer::class);

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Circular dependency detected: %s -> %s -> %s',
                ObjectConsumer::class,
                stdClass::class,
                ObjectConsumer::class,
            ),
        );

        $container->get(ObjectConsumer::class);
    }
}
