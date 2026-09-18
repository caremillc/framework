<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Attribute\Inject;
use Careminate\Container\Attribute\Tagged;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\AttributedService;
use CareminateIntegration\Tests\Fixtures\Container\ConflictingAttributedService;
use CareminateIntegration\Tests\Fixtures\Container\InheritedAttributedService;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Throwable;
use TypeError;

final class AttributeAutowireTest extends TestCase
{
    public function testAttributesResolveNamedCompositeAndTaggedArguments(): void
    {
        $container = self::configuredContainer();

        $container->autowire('service', AttributedService::class);

        $service = $container->get('service');

        self::assertInstanceOf(AttributedService::class, $service);

        self::assertSame(
            $container->get('attribute.dependency'),
            $service->dependency,
        );

        self::assertSame(['handler value'], $service->handlers);
        self::assertSame('choice', $service->choice);
        self::assertNull($service->optional);
    }

    public function testInheritedConstructorAttributesAreRetained(): void
    {
        $container = self::configuredContainer();

        $container->autowire('service', InheritedAttributedService::class);

        $service = $container->get('service');

        self::assertInstanceOf(InheritedAttributedService::class, $service);
        self::assertSame(['handler value'], $service->handlers);
        self::assertSame('choice', $service->choice);
    }

    public function testExplicitMappingsOverrideAttributesAndContext(): void
    {
        $container = self::configuredContainer();
        $explicit = new Container();

        $container->register('context.target', new Container());
        $container->register('alternative.handler', 'alternative');
        $container->tag('alternative.handlers', 'alternative.handler');

        $container->autowire(
            'service',
            AttributedService::class,
            arguments: [
                'dependency' => $explicit,
                'choice' => 0,
            ],
            taggedArguments: ['handlers' => 'alternative.handlers'],
        );

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'context.target',
        );

        $service = $container->get('service');

        self::assertInstanceOf(AttributedService::class, $service);
        self::assertSame($explicit, $service->dependency);
        self::assertSame(0, $service->choice);
        self::assertSame(['alternative'], $service->handlers);
    }

    public function testContextOverridesAttributeSelection(): void
    {
        $container = self::configuredContainer();
        $contextual = new Container();

        $container->register('context.target', $contextual);
        $container->autowire('service', AttributedService::class);

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'context.target',
        );

        $service = $container->get('service');

        self::assertInstanceOf(AttributedService::class, $service);
        self::assertSame($contextual, $service->dependency);
    }

    public function testLiteralArrayOverridesTaggedAttribute(): void
    {
        $container = self::configuredContainer();

        $container->autowire(
            'service',
            AttributedService::class,
            arguments: ['handlers' => ['literal']],
        );

        $service = $container->get('service');

        self::assertInstanceOf(AttributedService::class, $service);
        self::assertSame(['literal'], $service->handlers);
    }

    public function testTargetsMayBeRegisteredAfterAutowireRegistration(): void
    {
        $container = new Container();

        $container->autowire('service', AttributedService::class);

        self::assertTrue($container->has('service'));

        $container->register('attribute.dependency', new Container());
        $container->register('attribute.choice', 12);
        $container->register('attribute.optional', null);

        $service = $container->get('service');

        self::assertInstanceOf(AttributedService::class, $service);
        self::assertSame(12, $service->choice);
        self::assertSame([], $service->handlers);
    }

    public function testMissingAttributedTargetDoesNotUseDeclaredDefault(): void
    {
        $container = new Container();

        $container->register('attribute.dependency', new Container());
        $container->register('attribute.choice', 'choice');
        $container->autowire('service', AttributedService::class);

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);

        self::assertInstanceOf(
            EntryNotFoundException::class,
            $failure->getPrevious(),
        );

        self::assertSame(
            ['service', 'attribute.optional'],
            $failure->dependencyPath(),
        );
    }

    public function testIncompatibleAttributedValuePreservesTypeError(): void
    {
        $container = new Container();

        $container->register('attribute.dependency', new Container());
        $container->register('attribute.choice', false);
        $container->register('attribute.optional', null);
        $container->autowire('service', AttributedService::class);

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
    }

    public function testConflictingAttributesAreRejectedDespiteExplicitOverride(): void
    {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->autowire(
                    'service',
                    ConflictingAttributedService::class,
                    arguments: ['handlers' => []],
                );
            },
        );

        self::assertInstanceOf(ResolutionException::class, $failure);

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));
    }

    public function testInjectRejectsAnEmptyIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Inject('');
    }

    public function testTaggedRejectsAnEmptyTagName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Tagged('');
    }

    public function testAttributeTargetParticipatesInCycleDiagnostics(): void
    {
        $container = new Container();

        $container->autowire('service', AttributedService::class);
        $container->alias('attribute.dependency', 'service');

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);

        self::assertSame(
            ['service', 'attribute.dependency'],
            $failure->dependencyPath(),
        );
    }

    public function testScopedAutowiringUsesAttributesWithinTheActiveScope(): void
    {
        $container = new Container();

        $container->scoped(
            'attribute.dependency',
            static fn (ContainerInterface $resolver): Container => new Container(),
        );

        $container->register('attribute.choice', 'choice');
        $container->register('attribute.optional', null);
        $container->scopedAutowire('service', AttributedService::class);
        $container->freeze();

        $first = $container->runInScope(
            static function (ContainerInterface $resolver): mixed {
                $service = $resolver->get('service');

                self::assertInstanceOf(AttributedService::class, $service);

                self::assertSame(
                    $resolver->get('attribute.dependency'),
                    $service->dependency,
                );

                self::assertSame($service, $resolver->get('service'));

                return $service;
            },
        );

        $second = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(AttributedService::class, $first);
        self::assertInstanceOf(AttributedService::class, $second);
        self::assertNotSame($first->dependency, $second->dependency);
    }

    public function testSingletonCannotAcquireCachedAttributedScopedTarget(): void
    {
        $container = new Container();

        $container->scoped(
            'attribute.dependency',
            static fn (ContainerInterface $resolver): Container => new Container(),
        );

        $container->autowire(
            'service',
            AttributedService::class,
            shared: true,
        );

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                $resolver->get('attribute.dependency');

                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('service'),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);

                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );

                self::assertSame(
                    ['service', 'attribute.dependency'],
                    $failure->dependencyPath(),
                );
            },
        );
    }

    private static function configuredContainer(): Container
    {
        $container = new Container();

        $container->register('attribute.dependency', new Container());
        $container->register('attribute.choice', 'choice');
        $container->register('attribute.optional', null);
        $container->register('handler', 'handler value');
        $container->tag('attribute.handlers', 'handler');

        return $container;
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
