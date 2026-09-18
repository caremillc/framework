<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Internal\AutowireFactory;
use Careminate\Container\Internal\LazyClass;
use Careminate\Exception\FrameworkException;
use CareminateIntegration\Tests\Fixtures\Container\LazyStateService;
use CareminateIntegration\Tests\Fixtures\Container\PropertylessLazyService;
use Closure;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;
use TypeError;

final class LazyClassTest extends TestCase
{
    public function testInitializationIsLazyAndPreservesObjectIdentity(): void
    {
        $container = new Container();
        $lazy = new LazyClass(LazyStateService::class);

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $plan = new AutowireFactory(
            LazyStateService::class,
            arguments: [
                'count' => 7,
                'observer' => static function (LazyStateService $service) use ($calls): void {
                    $calls->append(true);
                },
            ],
        );

        $ghost = $lazy->create(
            static function (object $object) use ($lazy, $plan, $container): void {
                $lazy->initialize(
                    $object,
                    $plan->resolveArguments($container, $container->tagged(...)),
                );
            },
        );

        self::assertInstanceOf(LazyStateService::class, $ghost);
        self::assertTrue($lazy->isUninitialized($ghost));
        self::assertCount(0, $calls);

        $sameObject = $ghost;

        self::assertSame('service', $ghost->kind());
        self::assertTrue($lazy->isUninitialized($ghost));
        self::assertCount(0, $calls);

        self::assertSame(7, $ghost->count);
        self::assertSame('default', $ghost->label);
        self::assertFalse($lazy->isUninitialized($ghost));
        self::assertSame($sameObject, $ghost);
        self::assertCount(1, $calls);
    }

    public function testConstructorArgumentsRetainStrictTypes(): void
    {
        $container = new Container();
        $lazy = new LazyClass(LazyStateService::class);

        $plan = new AutowireFactory(
            LazyStateService::class,
            arguments: ['count' => '7'],
        );

        $ghost = $lazy->create(
            static function (object $object) use ($lazy, $plan, $container): void {
                $lazy->initialize(
                    $object,
                    $plan->resolveArguments($container, $container->tagged(...)),
                );
            },
        );

        self::assertInstanceOf(LazyStateService::class, $ghost);

        $failure = self::captureFailure(
            static fn (): int => $ghost->count,
        );

        self::assertInstanceOf(TypeError::class, $failure);
        self::assertTrue($lazy->isUninitialized($ghost));
    }

    public function testFailedInitializationCanRetryOnTheSameGhost(): void
    {
        $container = new Container();
        $lazy = new LazyClass(LazyStateService::class);
        $original = new Error('First initialization failed.');

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $plan = new AutowireFactory(
            LazyStateService::class,
            arguments: [
                'count' => 9,
                'observer' => static function (LazyStateService $service) use (
                    $calls,
                    $original,
                ): void {
                    $calls->append(true);

                    if ($calls->count() === 1) {
                        throw $original;
                    }
                },
            ],
        );

        $ghost = $lazy->create(
            static function (object $object) use ($lazy, $plan, $container): void {
                $lazy->initialize(
                    $object,
                    $plan->resolveArguments($container, $container->tagged(...)),
                );
            },
        );

        self::assertInstanceOf(LazyStateService::class, $ghost);

        $failure = self::captureFailure(
            static fn (): int => $ghost->count,
        );

        self::assertSame($original, $failure);
        self::assertTrue($lazy->isUninitialized($ghost));
        self::assertCount(1, $calls);

        self::assertSame(9, $ghost->count);
        self::assertFalse($lazy->isUninitialized($ghost));
        self::assertCount(2, $calls);
    }

    public function testCloningInitializesTheOriginalBeforeCreatingTheClone(): void
    {
        $lazy = new LazyClass(LazyStateService::class);

        $ghost = $lazy->create(
            static function (object $object) use ($lazy): void {
                $lazy->initialize($object, [11]);
            },
        );

        self::assertInstanceOf(LazyStateService::class, $ghost);
        self::assertTrue($lazy->isUninitialized($ghost));

        $copy = clone $ghost;

        self::assertFalse($lazy->isUninitialized($ghost));
        self::assertFalse($lazy->isUninitialized($copy));
        self::assertNotSame($ghost, $copy);
        self::assertSame(11, $ghost->count);
        self::assertSame(11, $copy->count);
    }

    public function testSerializationUsesNativeInitializationBehavior(): void
    {
        $lazy = new LazyClass(LazyStateService::class);

        $ghost = $lazy->create(
            static function (object $object) use ($lazy): void {
                $lazy->initialize($object, [13]);
            },
        );

        self::assertTrue($lazy->isUninitialized($ghost));

        $serialized = serialize($ghost);

        self::assertFalse($lazy->isUninitialized($ghost));

        $restored = unserialize(
            $serialized,
            ['allowed_classes' => [LazyStateService::class]],
        );

        self::assertInstanceOf(LazyStateService::class, $restored);
        self::assertSame(13, $restored->count);
        self::assertSame('default', $restored->label);
        self::assertNotSame($ghost, $restored);
    }

    #[DataProvider('unsupportedClasses')]
    public function testUnsupportedClassesAreRejected(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LazyClass($class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedClasses(): iterable
    {
        yield 'unknown class' => [
            'CareminateIntegration\\Tests\\Fixtures\\Container\\MissingLazyClass',
        ];

        yield 'internal class' => [DateTimeImmutable::class];
        yield 'no constructor' => [stdClass::class];
        yield 'abstract class' => [FrameworkException::class];
        yield 'no instance state' => [PropertylessLazyService::class];
    }

    public function testInitializationRejectsAnObjectOfAnotherClass(): void
    {
        $lazy = new LazyClass(LazyStateService::class);

        $this->expectException(InvalidArgumentException::class);

        $lazy->initialize(new stdClass(), [1]);
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
