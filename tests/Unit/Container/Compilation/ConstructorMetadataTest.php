<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Container;
use Careminate\Container\Internal\AutowireFactory;
use Careminate\Container\Internal\ConstructorMetadata;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use CareminateIntegration\Tests\Fixtures\Container\PlannedDefaultsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;
use stdClass;

final class ConstructorMetadataTest extends TestCase
{
    public function testDiscoveryPreservesParameterOrderAndDependencies(): void
    {
        $metadata = new ConstructorMetadata(
            DefinitionConsumer::class,
            arguments: ['label' => 'configured'],
            taggedArguments: ['handlers' => 'handlers'],
        );

        self::assertSame(DefinitionConsumer::class, $metadata->className);

        self::assertSame(
            ['dependency', 'handlers', 'label'],
            array_column($metadata->parameters, 'name'),
        );

        self::assertSame(
            DefinitionDependency::class,
            $metadata->parameters[0]['dependency'],
        );
        self::assertNull($metadata->parameters[0]['default']);

        self::assertInstanceOf(
            ReflectionParameter::class,
            $metadata->parameters[1]['default'],
        );
        self::assertInstanceOf(
            ReflectionParameter::class,
            $metadata->parameters[2]['default'],
        );

        self::assertSame(['label' => 'configured'], $metadata->arguments);
        self::assertSame(
            ['handlers' => 'handlers'],
            $metadata->taggedArguments,
        );
    }

    public function testDependencySupportMatchesTheFactory(): void
    {
        $metadata = new ConstructorMetadata(DefinitionConsumer::class);
        $factory = new AutowireFactory(DefinitionConsumer::class);

        self::assertTrue(
            $metadata->supportsDependency(DefinitionDependency::class),
        );
        self::assertTrue(
            $factory->supportsDependency(DefinitionDependency::class),
        );

        self::assertFalse($metadata->supportsDependency('unknown'));
        self::assertFalse($factory->supportsDependency('unknown'));
    }

    public function testConstructorlessClassHasNoParameters(): void
    {
        $metadata = new ConstructorMetadata(stdClass::class);

        self::assertSame(stdClass::class, $metadata->className);
        self::assertSame([], $metadata->parameters);
        self::assertSame([], $metadata->arguments);
        self::assertSame([], $metadata->taggedArguments);
    }

    public function testRuntimeObjectArgumentsRemainSupported(): void
    {
        $dependency = new DefinitionDependency('explicit');

        $metadata = new ConstructorMetadata(
            DefinitionConsumer::class,
            arguments: ['dependency' => $dependency],
        );

        self::assertSame(
            $dependency,
            $metadata->arguments['dependency'],
        );

        $container = new Container();
        $factory = new AutowireFactory(
            DefinitionConsumer::class,
            arguments: ['dependency' => $dependency],
        );

        $service = $factory($container, $container->tagged(...));

        self::assertInstanceOf(DefinitionConsumer::class, $service);
        self::assertSame($dependency, $service->dependency);
    }

    public function testFactoryRetainsContextTagsAndLiteralPrecedence(): void
    {
        $container = new Container();
        $dependency = new DefinitionDependency('contextual');

        $container->register('context.target', $dependency);
        $container->register('handler', 'handled');
        $container->tag('handlers', 'handler');

        $factory = new AutowireFactory(
            DefinitionConsumer::class,
            arguments: ['label' => 'configured'],
            taggedArguments: ['handlers' => 'handlers'],
        );

        $arguments = $factory->resolveArguments(
            $container,
            $container->tagged(...),
            [DefinitionDependency::class => 'context.target'],
        );

        self::assertSame(
            [$dependency, ['handled'], 'configured'],
            $arguments,
        );

        $explicit = new DefinitionDependency('explicit');

        $explicitFactory = new AutowireFactory(
            DefinitionConsumer::class,
            arguments: ['dependency' => $explicit],
        );

        $service = $explicitFactory(
            $container,
            $container->tagged(...),
            [DefinitionDependency::class => 'context.target'],
        );

        self::assertInstanceOf(DefinitionConsumer::class, $service);
        self::assertSame($explicit, $service->dependency);
    }

    public function testFactoryStillCreatesIndependentDefaultObjects(): void
    {
        $container = new Container();
        $factory = new AutowireFactory(PlannedDefaultsService::class);

        $first = $factory($container, $container->tagged(...));
        $second = $factory($container, $container->tagged(...));

        self::assertInstanceOf(PlannedDefaultsService::class, $first);
        self::assertInstanceOf(PlannedDefaultsService::class, $second);

        self::assertNotSame($first->token, $second->token);
        self::assertSame('default', $first->optional);
        self::assertSame('default', $second->optional);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $tags
     */
    #[DataProvider('invalidConfigurations')]
    public function testDiscoveryRejectsInvalidConfiguration(
        array $arguments,
        array $tags,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ConstructorMetadata(
            DefinitionConsumer::class,
            $arguments,
            $tags,
        );
    }

    /**
     * @return iterable<string, array{
     *     array<array-key, mixed>,
     *     array<array-key, mixed>,
     *     string
     * }>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'numeric argument key' => [
            [1],
            [],
            'Constructor argument keys must be non-empty parameter names.',
        ];

        yield 'numeric tagged key' => [
            [],
            ['handlers'],
            'Tagged argument keys must be non-empty parameter names.',
        ];

        yield 'empty tag' => [
            [],
            ['handlers' => ''],
            'Tagged arguments must reference non-empty tag names.',
        ];

        yield 'literal null conflicts with tag' => [
            ['handlers' => null],
            ['handlers' => 'handlers'],
            'A parameter cannot have both a literal and tagged argument.',
        ];

        yield 'tag on string parameter' => [
            [],
            ['label' => 'handlers'],
            'A tagged argument requires an array parameter.',
        ];

        yield 'unknown literal parameter' => [
            ['unknown' => null],
            [],
            'An explicit argument does not match a constructor parameter.',
        ];

        yield 'unknown tagged parameter' => [
            [],
            ['unknown' => 'handlers'],
            'A tagged argument does not match a constructor parameter.',
        ];
    }
}
