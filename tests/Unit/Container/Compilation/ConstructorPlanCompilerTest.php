<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Exception\PortableValueException;
use Careminate\Container\Compilation\Internal\ConstructorPlanCompiler;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Internal\AutowireFactory;
use Careminate\Container\Internal\ConstructorMetadata;
use CareminateIntegration\Tests\Fixtures\Container\CompiledAttributedService;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use CareminateIntegration\Tests\Fixtures\Container\PlannedDefaultsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use TypeError;

final class ConstructorPlanCompilerTest extends TestCase
{
    public function testAttributesResolveThroughTheCompiledPlan(): void
    {
        $container = new Container();
        $dependency = new DefinitionDependency('attribute');

        $container->register('attribute.dependency', $dependency);
        $container->register('attribute.label', 'attribute label');
        $container->register('handler', 'handled');
        $container->tag('attribute.handlers', 'handler');

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(CompiledAttributedService::class),
        );

        $service = $prepared($container, $container->tagged(...));

        self::assertInstanceOf(CompiledAttributedService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame(['handled'], $service->handlers);
        self::assertSame('attribute label', $service->label);
    }

    public function testOverridesMatchReflectiveFactoryBehavior(): void
    {
        $container = new Container();
        $contextual = new DefinitionDependency('contextual');

        $container->register('context.target', $contextual);
        $container->register('handler', 'configured handler');
        $container->tag('configured.handlers', 'handler');

        $arguments = ['label' => null];
        $tags = ['handlers' => 'configured.handlers'];
        $contexts = [DefinitionDependency::class => 'context.target'];

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                CompiledAttributedService::class,
                $arguments,
                $tags,
            ),
            $contexts,
        );

        $reflective = new AutowireFactory(
            CompiledAttributedService::class,
            $arguments,
            $tags,
        );

        $compiledService = $prepared($container, $container->tagged(...));
        $reflectiveService = $reflective(
            $container,
            $container->tagged(...),
            $contexts,
        );

        self::assertInstanceOf(
            CompiledAttributedService::class,
            $compiledService,
        );
        self::assertInstanceOf(
            CompiledAttributedService::class,
            $reflectiveService,
        );

        self::assertSame($contextual, $compiledService->dependency);
        self::assertSame(
            $reflectiveService->dependency,
            $compiledService->dependency,
        );
        self::assertSame(
            ['configured handler'],
            $compiledService->handlers,
        );
        self::assertSame(
            $reflectiveService->handlers,
            $compiledService->handlers,
        );
        self::assertNull($compiledService->label);
        self::assertSame(
            $reflectiveService->label,
            $compiledService->label,
        );
    }

    public function testExplicitLiteralCanReplaceATagAttribute(): void
    {
        $container = new Container();
        $dependency = new DefinitionDependency();

        $container->register('attribute.dependency', $dependency);

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                CompiledAttributedService::class,
                arguments: [
                    'handlers' => ['literal handler'],
                    'label' => null,
                ],
            ),
        );

        $service = $prepared($container, $container->tagged(...));

        self::assertInstanceOf(CompiledAttributedService::class, $service);
        self::assertSame(['literal handler'], $service->handlers);
        self::assertNull($service->label);
    }

    public function testInjectAttributeDoesNotFallBackToDeclaredDefault(): void
    {
        $container = new Container();

        $container->register(
            'attribute.dependency',
            new DefinitionDependency(),
        );

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(CompiledAttributedService::class),
        );

        $this->expectException(EntryNotFoundException::class);

        $prepared($container, $container->tagged(...));
    }

    public function testDefaultObjectsAreNotRetainedInThePlan(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                PlannedDefaultsService::class,
                arguments: ['label' => 'configured'],
            ),
        );

        self::assertSame(
            ['label' => 'configured'],
            $prepared->resolveArguments($container, $container->tagged(...)),
        );

        $first = $prepared($container, $container->tagged(...));
        $second = $prepared($container, $container->tagged(...));

        self::assertInstanceOf(PlannedDefaultsService::class, $first);
        self::assertInstanceOf(PlannedDefaultsService::class, $second);

        self::assertNotSame($first->token, $second->token);
        self::assertSame('default', $first->optional);
        self::assertSame('configured', $first->label);
    }

    public function testRegisteredOptionalDependencyOverridesDefault(): void
    {
        $container = new Container();
        $token = new stdClass();

        $container->register(stdClass::class, $token);

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(PlannedDefaultsService::class),
        );

        $service = $prepared($container, $container->tagged(...));

        self::assertInstanceOf(PlannedDefaultsService::class, $service);
        self::assertSame($token, $service->token);
    }

    public function testRequiredNamedDependencyIsResolved(): void
    {
        $container = new Container();
        $dependency = new DefinitionDependency();

        $container->register(DefinitionDependency::class, $dependency);

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(DefinitionConsumer::class),
        );

        $service = $prepared($container, $container->tagged(...));

        self::assertInstanceOf(DefinitionConsumer::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame([], $service->handlers);
        self::assertSame('default', $service->label);
    }

    public function testConstructorTypeChecksRemainStrict(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                DefinitionDependency::class,
                arguments: ['label' => 123],
            ),
        );

        $this->expectException(TypeError::class);

        $prepared($container, $container->tagged(...));
    }

    public function testObjectLiteralCannotEnterPortablePlan(): void
    {
        $metadata = new ConstructorMetadata(
            DefinitionConsumer::class,
            arguments: ['dependency' => new DefinitionDependency()],
        );

        $this->expectException(PortableValueException::class);

        new ConstructorPlanCompiler()->compile($metadata);
    }

    public function testExistingContainerCanOwnPreparedSingletonLifetime(): void
    {
        $container = new Container();

        $prepared = new ConstructorPlanCompiler()->compile(
            new ConstructorMetadata(
                DefinitionDependency::class,
                arguments: ['label' => 'singleton'],
            ),
        );

        $container->singleton(
            'service',
            static fn (ContainerInterface $resolver): object => $prepared(
                $resolver,
                $container->tagged(...),
            ),
        );

        $first = $container->get('service');

        self::assertInstanceOf(DefinitionDependency::class, $first);
        self::assertSame('singleton', $first->label);
        self::assertSame($first, $container->get('service'));
    }

    /**
     * @param array<array-key, mixed> $contexts
     */
    #[DataProvider('invalidContexts')]
    public function testInvalidContextConfigurationIsRejected(
        array $contexts,
    ): void {
        $metadata = new ConstructorMetadata(DefinitionConsumer::class);

        $this->expectException(DefinitionException::class);

        new ConstructorPlanCompiler()->compile($metadata, $contexts);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'numeric dependency' => [[0 => 'target']];
        yield 'empty dependency' => [['' => 'target']];
        yield 'empty target' => [[DefinitionDependency::class => '']];
        yield 'non-string target' => [[DefinitionDependency::class => null]];
        yield 'unmatched dependency' => [['UnknownDependency' => 'target']];
    }
}
