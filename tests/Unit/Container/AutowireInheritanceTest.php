<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\InheritanceBase;
use CareminateIntegration\Tests\Fixtures\Container\InheritanceChild;
use CareminateIntegration\Tests\Fixtures\Container\InheritanceRoot;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TypeError;

final class AutowireInheritanceTest extends TestCase
{
    public function testInheritedConstructorUsesItsDeclaredDefaults(): void
    {
        $container = new Container();

        $container->autowire('service', InheritanceChild::class);

        $service = $container->get('service');

        self::assertInstanceOf(InheritanceChild::class, $service);
        self::assertNull($service->owner);
        self::assertNull($service->ancestor);
        self::assertSame('inherited', $service->label);
    }

    public function testSelfAndParentUseTheConstructorDeclaringClass(): void
    {
        $container = new Container();
        $owner = new InheritanceBase();
        $ancestor = new InheritanceRoot();

        $container->register(InheritanceBase::class, $owner);
        $container->register(InheritanceRoot::class, $ancestor);

        $container->factory(
            InheritanceChild::class,
            static function (ContainerInterface $resolver): never {
                throw new Error('The child type must not replace self.');
            },
        );

        $container->autowire('service', InheritanceChild::class);

        $service = $container->get('service');

        self::assertInstanceOf(InheritanceChild::class, $service);
        self::assertSame($owner, $service->owner);
        self::assertSame($ancestor, $service->ancestor);
    }

    public function testRelativeTypeDependenciesCanUseAliases(): void
    {
        $container = new Container();
        $owner = new InheritanceBase();
        $ancestor = new InheritanceRoot();

        $container->register('owner', $owner);
        $container->register('ancestor', $ancestor);

        $container->alias(InheritanceBase::class, 'owner');
        $container->alias(InheritanceRoot::class, 'ancestor');

        $container->autowire('service', InheritanceChild::class);

        $service = $container->get('service');

        self::assertInstanceOf(InheritanceChild::class, $service);
        self::assertSame($owner, $service->owner);
        self::assertSame($ancestor, $service->ancestor);
    }

    public function testExplicitArgumentsOverrideInheritedDependencies(): void
    {
        $container = new Container();
        $owner = new InheritanceBase();

        foreach (
            [InheritanceBase::class, InheritanceRoot::class] as $identifier
        ) {
            $container->factory(
                $identifier,
                static function (ContainerInterface $resolver): never {
                    throw new Error('An explicit argument must bypass lookup.');
                },
            );
        }

        $container->autowire(
            'service',
            InheritanceChild::class,
            arguments: [
                'owner' => $owner,
                'ancestor' => null,
                'label' => 'explicit',
            ],
        );

        $service = $container->get('service');

        self::assertInstanceOf(InheritanceChild::class, $service);
        self::assertSame($owner, $service->owner);
        self::assertNull($service->ancestor);
        self::assertSame('explicit', $service->label);
    }

    public function testInvalidSelfDependencyDoesNotFallBackToNull(): void
    {
        $container = new Container();

        $container->register(
            InheritanceBase::class,
            new InheritanceRoot(),
        );

        $container->autowire('service', InheritanceChild::class);

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            'The requested entry could not be resolved.',
            $failure->getMessage(),
        );

        self::assertSame(['service'], $failure->dependencyPath());
        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
    }

    public function testInheritedDependencyCyclePreservesItsLookupPath(): void
    {
        $container = new Container();

        $container->factory(
            InheritanceBase::class,
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        $container->autowire('service', InheritanceChild::class);

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            ['service', InheritanceBase::class, 'service'],
            $failure->dependencyPath(),
        );

        $container->autowire(
            'recovered',
            InheritanceChild::class,
            arguments: ['owner' => null],
        );

        $service = $container->get('recovered');

        self::assertInstanceOf(InheritanceChild::class, $service);
        self::assertNull($service->owner);
        self::assertNull($service->ancestor);

        self::assertSame(
            ['service', InheritanceBase::class, 'service'],
            $failure->dependencyPath(),
        );
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
