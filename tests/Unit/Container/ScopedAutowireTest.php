<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Exception\ScopeStateException;
use CareminateIntegration\Tests\Fixtures\Container\CompositeService;
use CareminateIntegration\Tests\Fixtures\Container\ConstructorService;
use CareminateIntegration\Tests\Fixtures\Container\TaggedPipeline;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Throwable;
use TypeError;

final class ScopedAutowireTest extends TestCase
{
    public function testScopedAutowiringSharesAliasesAndSeparatesScopes(): void
    {
        $container = new Container();

        $container->scopedAutowire('service', stdClass::class);
        $container->alias('alias', 'service');
        $container->freeze();

        $first = $container->runInScope(
            static function (ContainerInterface $resolver): mixed {
                $service = $resolver->get('service');

                self::assertSame($service, $resolver->get('alias'));

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
    }

    public function testOutsideScopeLookupIsRejected(): void
    {
        $container = new Container();
        $container->scopedAutowire('service', stdClass::class);

        self::assertTrue($container->has('service'));

        $this->expectException(ScopeStateException::class);

        $container->get('service');
    }

    public function testNamedDependenciesAndLiteralArgumentsArePreserved(): void
    {
        $container = new Container();
        $dependency = new Container();

        $container->register(ContainerInterface::class, $dependency);

        $container->scopedAutowire(
            'service',
            ConstructorService::class,
            arguments: ['label' => 'scoped'],
        );

        $service = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame('scoped', $service->label);
        self::assertNull($service->optional);
    }

    public function testTaggedScopedDependenciesShareTheOwnerScope(): void
    {
        $container = new Container();

        $container->scopedAutowire('handler', stdClass::class);
        $container->tag('handlers', 'handler');

        $container->scopedAutowire(
            'pipeline',
            TaggedPipeline::class,
            arguments: ['name' => 'scoped pipeline'],
            taggedArguments: ['handlers' => 'handlers'],
        );

        $first = $container->runInScope(
            static function (ContainerInterface $resolver): mixed {
                $pipeline = $resolver->get('pipeline');

                self::assertInstanceOf(TaggedPipeline::class, $pipeline);
                self::assertSame([$resolver->get('handler')], $pipeline->handlers);
                self::assertSame('scoped pipeline', $pipeline->name);
                self::assertSame($pipeline, $resolver->get('pipeline'));

                return $pipeline;
            },
        );

        $second = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'pipeline',
            ),
        );

        self::assertInstanceOf(TaggedPipeline::class, $first);
        self::assertInstanceOf(TaggedPipeline::class, $second);
        self::assertNotSame($first, $second);
        self::assertCount(1, $first->handlers);
        self::assertCount(1, $second->handlers);
        self::assertNotSame($first->handlers[0], $second->handlers[0]);
    }

    public function testCompositeArgumentsAndDefaultsRemainAvailable(): void
    {
        $container = new Container();
        $collection = new ArrayObject([1]);
        $stream = new stdClass();

        $container->scopedAutowire(
            'service',
            CompositeService::class,
            arguments: [
                'choice' => 'scoped',
                'collection' => $collection,
                'stream' => $stream,
            ],
        );

        $service = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(CompositeService::class, $service);
        self::assertSame('scoped', $service->choice);
        self::assertSame($collection, $service->collection);
        self::assertSame($stream, $service->stream);
        self::assertSame('fallback', $service->fallback);
        self::assertNull($service->optional);
    }

    public function testSingletonCannotAcquireCachedScopedAutowiringThroughTags(): void
    {
        $container = new Container();

        $container->scopedAutowire('handler', stdClass::class);
        $container->tag('handlers', 'handler');

        $container->autowire(
            'pipeline',
            TaggedPipeline::class,
            shared: true,
            taggedArguments: ['handlers' => 'handlers'],
        );

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                $handler = $resolver->get('handler');

                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('pipeline'),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertSame(
                    ['pipeline', 'handler'],
                    $failure->dependencyPath(),
                );

                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );

                self::assertSame($handler, $resolver->get('handler'));
            },
        );
    }

    public function testInvalidConstructionCanBeRetriedWithoutLeavingCycleState(): void
    {
        $container = new Container();

        $container->scopedAutowire(
            'broken',
            TaggedPipeline::class,
            arguments: ['handlers' => 'invalid'],
        );

        $container->scopedAutowire('healthy', stdClass::class);

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                foreach ([1, 2] as $attempt) {
                    $failure = self::captureFailure(
                        static fn (): mixed => $resolver->get('broken'),
                    );

                    self::assertInstanceOf(ResolutionException::class, $failure);
                    self::assertInstanceOf(TypeError::class, $failure->getPrevious());
                    self::assertSame(['broken'], $failure->dependencyPath());
                }

                self::assertInstanceOf(stdClass::class, $resolver->get('healthy'));
            },
        );
    }

    public function testInvalidDefinitionDoesNotReserveTheIdentifier(): void
    {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->scopedAutowire(
                    'service',
                    TaggedPipeline::class,
                    arguments: ['handlers' => []],
                    taggedArguments: ['handlers' => 'handlers'],
                );
            },
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));

        $container->register('service', 'replacement');

        self::assertSame('replacement', $container->get('service'));
    }

    public function testScopedAutowiringRegistrationRespectsFreeze(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->scopedAutowire('service', stdClass::class);
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
