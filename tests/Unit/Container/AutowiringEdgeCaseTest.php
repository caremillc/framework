<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ContainerException;
use PHPUnit\Framework\TestCase;
use stdClass;

interface FirstContract
{
}

interface SecondContract
{
}

final class UnionDependencyService
{
    public function __construct(
        public FirstContract|SecondContract $dependency
    ) {
    }
}

final class IntersectionDependencyService
{
    public function __construct(
        public FirstContract&SecondContract $dependency
    ) {
    }
}


final class VariadicDependencyService
{
    /**
     * @var list<stdClass>
     */
    public array $dependencies;

    public function __construct(stdClass ...$dependencies)
    {
        $this->dependencies = array_values($dependencies);
    }
}



final class SelfReferencingService
{
    public function __construct(
        public self $dependency
    ) {
    }
}


class ParentDependency
{
}

final class ChildWithParentDependency extends ParentDependency
{
    public function __construct(
        public parent $dependency
    ) {
    }
}

final class NullableDependencyService
{
    public function __construct(
        public ?FirstContract $dependency = null
    ) {
    }
}

final class RequiredNullableDependencyService
{
    public function __construct(
        public ?FirstContract $dependency
    ) {
    }
}

final class MixedCycleFirst
{
    public function __construct(
        public MixedCycleSecond $second
    ) {
    }
}

final class MixedCycleSecond
{
    public function __construct(
        public MixedCycleFirst $first
    ) {
    }
}

final class AutowiringEdgeCaseTest extends TestCase
{
    public function testUnionTypeFailsClearly(): void
    {
        $container = new Container();
        $container->autowire(UnionDependencyService::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'unsupported union or intersection type'
        );

        $container->get(UnionDependencyService::class);
    }

    public function testIntersectionTypeFailsClearly(): void
    {
        $container = new Container();
        $container->autowire(IntersectionDependencyService::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'unsupported union or intersection type'
        );

        $container->get(IntersectionDependencyService::class);
    }

    public function testVariadicParameterReceivesNoArguments(): void
    {
        $container = new Container();
        $container->autowire(VariadicDependencyService::class);

        $service = $container->get(VariadicDependencyService::class);

        self::assertInstanceOf(
            VariadicDependencyService::class,
            $service
        );

        self::assertSame([], $service->dependencies);
    }

    public function testSelfTypeParticipatesInCycleDetection(): void
    {
        $container = new Container();
        $container->autowire(SelfReferencingService::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected'
        );

        $container->get(SelfReferencingService::class);
    }

    public function testParentTypeUsesRegisteredParentInstance(): void
    {
        $container = new Container();
        $parent = new ParentDependency();

        $container->instance(ParentDependency::class, $parent);
        $container->autowire(ChildWithParentDependency::class);

        $service = $container->get(ChildWithParentDependency::class);

        self::assertInstanceOf(
            ChildWithParentDependency::class,
            $service
        );

        self::assertSame($parent, $service->dependency);
    }

    public function testNullableDependencyWithDefaultUsesNull(): void
    {
        $container = new Container();
        $container->autowire(NullableDependencyService::class);

        $service = $container->get(NullableDependencyService::class);

        self::assertInstanceOf(
            NullableDependencyService::class,
            $service
        );

        self::assertNull($service->dependency);
    }

    public function testRequiredNullableDependencyStillNeedsBinding(): void
    {
        $container = new Container();
        $container->autowire(RequiredNullableDependencyService::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('parameter "$dependency"');

        $container->get(RequiredNullableDependencyService::class);
    }

    public function testMixedRegisteredAndImplicitCycleFails(): void
    {
        $container = new Container();
        $container->autowire(MixedCycleFirst::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected'
        );

        $container->get(MixedCycleFirst::class);
    }
}
