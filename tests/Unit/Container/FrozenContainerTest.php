<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\TaggedPipeline;
use Closure;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class FrozenContainerTest extends TestCase
{
    public function testContainerStartsMutableAndFreezeIsIdempotent(): void
    {
        $container = new Container();

        self::assertFalse($container->isFrozen());

        $container->freeze();

        self::assertTrue($container->isFrozen());

        $container->freeze();

        self::assertTrue($container->isFrozen());
    }

    /**
     * @param Closure(Container): void $operation
     */
    #[DataProvider('registrationOperations')]
    public function testEveryRegistrationMethodRejectsChangesAfterFreeze(
        Closure $operation,
    ): void {
        $container = new Container();

        $container->register('existing', 'original');
        $container->register('other', 'other value');
        $container->tag('group', 'existing');
        $container->freeze();

        try {
            $operation($container);
        } catch (FrozenContainerException $failure) {
            self::assertSame(
                'The container is frozen and cannot accept registration changes.',
                $failure->getMessage(),
            );

            self::assertFalse($container->has('new'));
            self::assertSame('original', $container->get('existing'));
            self::assertSame(['original'], $container->tagged('group'));
            self::assertTrue($container->isFrozen());

            return;
        }

        self::fail('Registration must fail after freezing.');
    }

    /**
     * @return iterable<string, array{Closure(Container): void}>
     */
    public static function registrationOperations(): iterable
    {
        yield 'value' => [
            static function (Container $container): void {
                $container->register('new', 'value');
            },
        ];

        yield 'factory' => [
            static function (Container $container): void {
                $container->factory(
                    'new',
                    static fn (ContainerInterface $resolver): string => 'value',
                );
            },
        ];

        yield 'singleton' => [
            static function (Container $container): void {
                $container->singleton(
                    'new',
                    static fn (ContainerInterface $resolver): string => 'value',
                );
            },
        ];

        yield 'autowire' => [
            static function (Container $container): void {
                $container->autowire('new', stdClass::class);
            },
        ];

        yield 'alias' => [
            static function (Container $container): void {
                $container->alias('new', 'existing');
            },
        ];

        yield 'tag' => [
            static function (Container $container): void {
                $container->tag('group', 'other');
            },
        ];
    }

    public function testFreezeTakesPrecedenceOverRegistrationValidation(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->register('', null);
    }

    public function testEvenEmptyTagRegistrationIsRejectedAfterFreeze(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->tag('group');
    }

    public function testFreezeDoesNotConstructServicesAndAllowsSingletonCaching(): void
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

        $container->alias('service.alias', 'service');
        $container->tag('services', 'service.alias');
        $container->freeze();

        self::assertCount(0, $calls);
        self::assertTrue($container->has('service'));
        self::assertTrue($container->has('service.alias'));

        $service = $container->get('service');

        self::assertInstanceOf(stdClass::class, $service);
        self::assertSame($service, $container->get('service.alias'));
        self::assertSame([$service], $container->tagged('services'));
        self::assertCount(1, $calls);
    }

    public function testTransientFactoriesStillRunForEachLookup(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->freeze();

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
    }

    public function testTaggedAutowiringContinuesAfterFreeze(): void
    {
        $container = new Container();

        $container->register('handler', 'handler value');
        $container->tag('handlers', 'handler');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            shared: true,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $container->freeze();

        $pipeline = $container->get('pipeline');

        self::assertInstanceOf(TaggedPipeline::class, $pipeline);
        self::assertSame(['handler value'], $pipeline->handlers);
        self::assertSame($pipeline, $container->get('pipeline'));
    }

    public function testUnknownLookupRetainsItsExistingException(): void
    {
        $container = new Container();
        $container->freeze();

        self::assertFalse($container->has('missing'));
        self::assertSame([], $container->tagged('missing'));

        $this->expectException(EntryNotFoundException::class);

        $container->get('missing');
    }

    public function testFactoryRegistrationAttemptIsWrappedAndLeavesNoEntry(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static function (ContainerInterface $resolver) use ($container): string {
                $container->register('late', 'late value');

                return 'unreachable';
            },
        );

        $container->register('healthy', 'healthy value');
        $container->freeze();

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(
            FrozenContainerException::class,
            $failure->getPrevious(),
        );

        self::assertSame(['service'], $failure->dependencyPath());
        self::assertFalse($container->has('late'));
        self::assertSame('healthy value', $container->get('healthy'));

        $retry = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(
            FrozenContainerException::class,
            $retry->getPrevious(),
        );

        self::assertSame(['service'], $retry->dependencyPath());
    }

    public function testFailedSingletonCanStillRetryWhileFrozen(): void
    {
        $container = new Container();
        $original = new Error('First construction failed.');
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

        $container->freeze();

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame($original, $failure->getPrevious());
        self::assertSame($service, $container->get('service'));
        self::assertSame($service, $container->get('service'));
        self::assertCount(2, $calls);
        self::assertTrue($container->isFrozen());
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
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
