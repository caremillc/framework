<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use ArrayObject;
use Careminate\Container\Compilation\Internal\ConstructorPlanCompiler;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Internal\ConstructorMetadata;
use Careminate\Container\Internal\LazyClass;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use CareminateIntegration\Tests\Fixtures\Container\PlannedDefaultsService;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use TypeError;

final class PreparedLazyArgumentsTest extends TestCase
{
    public function testPreparedArgumentsAreResolvedOnlyDuringInitialization(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                PlannedDefaultsService::class,
                arguments: [
                    'optional' => null,
                    'label' => 'configured',
                ],
            ),
        );

        $lazyClass = new LazyClass($prepared->className);

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $service = $lazyClass->create(
            static function (object $object) use (
                $container,
                $prepared,
                $lazyClass,
                $calls,
            ): void {
                $calls->append(true);

                $lazyClass->initialize(
                    $object,
                    $prepared->resolveArguments(
                        $container,
                        $container->tagged(...),
                    ),
                );
            },
        );

        self::assertInstanceOf(PlannedDefaultsService::class, $service);
        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertCount(0, $calls);

        self::assertSame('configured', $service->label);
        self::assertNull($service->optional);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertCount(1, $calls);

        self::assertSame('configured', $service->label);
        self::assertCount(1, $calls);
    }

    public function testSeparateGhostsReceiveSeparateDefaultObjects(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                PlannedDefaultsService::class,
                arguments: ['label' => 'configured'],
            ),
        );

        $lazyClass = new LazyClass($prepared->className);

        $initializer = static function (object $object) use (
            $container,
            $prepared,
            $lazyClass,
        ): void {
            $lazyClass->initialize(
                $object,
                $prepared->resolveArguments(
                    $container,
                    $container->tagged(...),
                ),
            );
        };

        $first = $lazyClass->create($initializer);
        $second = $lazyClass->create($initializer);

        self::assertInstanceOf(PlannedDefaultsService::class, $first);
        self::assertInstanceOf(PlannedDefaultsService::class, $second);

        self::assertTrue($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        self::assertNotSame($first->token, $second->token);
        self::assertSame('default', $first->optional);
        self::assertSame('configured', $second->label);

        self::assertFalse($lazyClass->isUninitialized($first));
        self::assertFalse($lazyClass->isUninitialized($second));
    }

    public function testNamedConstructorInvocationRemainsStrict(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                DefinitionDependency::class,
                arguments: ['label' => 123],
            ),
        );

        $lazyClass = new LazyClass($prepared->className);

        $service = $lazyClass->create(
            static function (object $object) use (
                $container,
                $prepared,
                $lazyClass,
            ): void {
                $lazyClass->initialize(
                    $object,
                    $prepared->resolveArguments(
                        $container,
                        $container->tagged(...),
                    ),
                );
            },
        );

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $failure = self::captureError(
            static fn (): string => $service->label,
        );

        self::assertInstanceOf(TypeError::class, $failure);
        self::assertTrue($lazyClass->isUninitialized($service));
    }

    public function testMissingDependencyCanBeSuppliedBeforeRetry(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(DefinitionConsumer::class),
        );

        $lazyClass = new LazyClass($prepared->className);

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $service = $lazyClass->create(
            static function (object $object) use (
                $container,
                $prepared,
                $lazyClass,
                $calls,
            ): void {
                $calls->append(true);

                $lazyClass->initialize(
                    $object,
                    $prepared->resolveArguments(
                        $container,
                        $container->tagged(...),
                    ),
                );
            },
        );

        self::assertInstanceOf(DefinitionConsumer::class, $service);

        $failure = self::captureMissingDependency(
            static fn (): DefinitionDependency => $service->dependency,
        );

        self::assertSame(
            [DefinitionDependency::class],
            $failure->dependencyPath(),
        );
        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertCount(1, $calls);

        $dependency = new DefinitionDependency('available');

        $container->register(DefinitionDependency::class, $dependency);

        self::assertSame($dependency, $service->dependency);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertCount(2, $calls);

        self::assertSame($dependency, $service->dependency);
        self::assertCount(2, $calls);
    }

    public function testUnknownNamedArgumentIsRejectedByConstructorInvocation(): void
    {
        $lazyClass = new LazyClass(DefinitionDependency::class);

        $service = $lazyClass->create(
            static function (object $object) use ($lazyClass): void {
                $lazyClass->initialize(
                    $object,
                    ['unknownParameter' => 'value'],
                );
            },
        );

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $failure = self::captureError(
            static fn (): string => $service->label,
        );

        self::assertStringContainsString(
            'Unknown named parameter',
            $failure->getMessage(),
        );
        self::assertTrue($lazyClass->isUninitialized($service));
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureError(Closure $operation): Error
    {
        try {
            $operation();
        } catch (Error $exception) {
            return $exception;
        }

        self::fail('Constructor initialization must fail.');
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureMissingDependency(
        Closure $operation,
    ): EntryNotFoundException {
        try {
            $operation();
        } catch (EntryNotFoundException $exception) {
            return $exception;
        }

        self::fail('The missing dependency must be reported.');
    }
}
