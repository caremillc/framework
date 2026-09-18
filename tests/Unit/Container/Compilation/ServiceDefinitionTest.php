<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Exception\PortableValueException;
use Careminate\Container\Compilation\ServiceDefinition;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ServiceDefinitionTest extends TestCase
{
    public function testNullIsAnExplicitLiteralValue(): void
    {
        $definition = ServiceDefinition::forValue('optional', null);

        self::assertSame('optional', $definition->id);
        self::assertTrue($definition->isValue());
        self::assertNull($definition->className);
        self::assertNull($definition->lifetime);
        self::assertFalse($definition->lazy);
        self::assertNull($definition->value());
        self::assertSame([], $definition->arguments());
        self::assertSame([], $definition->taggedArguments());
    }

    public function testLiteralValueIsDetachedFromInputReferences(): void
    {
        $shared = ['enabled' => true];
        $input = ['settings' => &$shared];

        $definition = ServiceDefinition::forValue('settings', $input);

        $shared['enabled'] = false;

        self::assertSame(
            ['settings' => ['enabled' => true]],
            $definition->value(),
        );
    }

    public function testReturnedValueCannotMutateStoredDefinition(): void
    {
        $definition = ServiceDefinition::forValue(
            'settings',
            ['enabled' => true],
        );

        $value = $definition->value();

        self::assertIsArray($value);

        $value['enabled'] = false;

        self::assertSame(['enabled' => true], $definition->value());
    }

    #[DataProvider('lifetimes')]
    public function testAutowireLifetimeIsPreserved(
        DefinitionLifetime $lifetime,
    ): void {
        $definition = ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
            lifetime: $lifetime,
        );

        self::assertFalse($definition->isValue());
        self::assertSame('Application\\Service', $definition->className);
        self::assertSame($lifetime, $definition->lifetime);
        self::assertFalse($definition->lazy);
    }

    /**
     * @return iterable<string, array{DefinitionLifetime}>
     */
    public static function lifetimes(): iterable
    {
        foreach (DefinitionLifetime::cases() as $lifetime) {
            yield $lifetime->value => [$lifetime];
        }
    }

    public function testAutowireDefaultsToTransient(): void
    {
        $definition = ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
        );

        self::assertSame(
            DefinitionLifetime::Transient,
            $definition->lifetime,
        );
    }

    public function testLazySingletonOptionsArePreserved(): void
    {
        $definition = ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'example', 'optional' => null],
            taggedArguments: ['handlers' => 'application.handlers'],
            lazy: true,
        );

        self::assertTrue($definition->lazy);
        self::assertSame(
            ['label' => 'example', 'optional' => null],
            $definition->arguments(),
        );
        self::assertSame(
            ['handlers' => 'application.handlers'],
            $definition->taggedArguments(),
        );
    }

    public function testArgumentsAndTagsAreDetachedFromInputReferences(): void
    {
        $label = 'original';
        $tag = 'original.handlers';

        $definition = ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
            arguments: ['label' => &$label],
            taggedArguments: ['handlers' => &$tag],
        );

        $label = 'changed';
        $tag = 'changed.handlers';

        self::assertSame(['label' => 'original'], $definition->arguments());
        self::assertSame(
            ['handlers' => 'original.handlers'],
            $definition->taggedArguments(),
        );

        $arguments = $definition->arguments();
        $arguments['label'] = 'another change';

        self::assertSame(['label' => 'original'], $definition->arguments());
    }

    public function testReturnedTagsCannotMutateStoredDefinition(): void
    {
        $definition = ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
            taggedArguments: ['handlers' => 'original.handlers'],
        );

        $tags = $definition->taggedArguments();
        $tags['handlers'] = 'changed.handlers';

        self::assertSame(
            ['handlers' => 'original.handlers'],
            $definition->taggedArguments(),
        );
    }

    public function testCaptureDoesNotInvokeAutoloaders(): void
    {
        $autoload = static function (string $className): void {
            self::fail('Definition capture must not invoke an autoloader.');
        };

        spl_autoload_register($autoload);

        try {
            $definition = ServiceDefinition::forAutowire(
                'future.service',
                'CareminateDefinitionFixture\\NotLoadedService',
            );

            self::assertSame(
                'CareminateDefinitionFixture\\NotLoadedService',
                $definition->className,
            );
        } finally {
            spl_autoload_unregister($autoload);
        }
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    #[DataProvider('invalidAutowireOptions')]
    public function testInvalidAutowireOptionsAreRejected(
        string $id,
        string $className,
        DefinitionLifetime $lifetime,
        array $arguments,
        array $taggedArguments,
        bool $lazy,
    ): void {
        $this->expectException(DefinitionException::class);

        ServiceDefinition::forAutowire(
            $id,
            $className,
            $lifetime,
            $arguments,
            $taggedArguments,
            $lazy,
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     string,
     *     DefinitionLifetime,
     *     array<array-key, mixed>,
     *     array<array-key, mixed>,
     *     bool
     * }>
     */
    public static function invalidAutowireOptions(): iterable
    {
        $transient = DefinitionLifetime::Transient;

        yield 'empty identifier' => [
            '', 'Example', $transient, [], [], false,
        ];
        yield 'empty class' => [
            'service', '', $transient, [], [], false,
        ];
        yield 'numeric argument key' => [
            'service', 'Example', $transient, [1], [], false,
        ];
        yield 'empty argument key' => [
            'service', 'Example', $transient, ['' => 1], [], false,
        ];
        yield 'numeric tagged key' => [
            'service', 'Example', $transient, [], ['handlers'], false,
        ];
        yield 'empty tagged key' => [
            'service', 'Example', $transient, [], ['' => 'handlers'], false,
        ];
        yield 'empty tag' => [
            'service', 'Example', $transient, [], ['items' => ''], false,
        ];
        yield 'non-string tag' => [
            'service', 'Example', $transient, [], ['items' => 1], false,
        ];
        yield 'null literal conflicts with tag' => [
            'service',
            'Example',
            $transient,
            ['items' => null],
            ['items' => 'handlers'],
            false,
        ];
        yield 'lazy transient' => [
            'service', 'Example', $transient, [], [], true,
        ];
        yield 'lazy scoped' => [
            'service', 'Example', DefinitionLifetime::Scoped, [], [], true,
        ];
    }

    public function testEmptyLiteralIdentifierIsRejected(): void
    {
        $this->expectException(DefinitionException::class);

        ServiceDefinition::forValue('', null);
    }

    public function testObjectLiteralIsRejected(): void
    {
        $this->expectException(PortableValueException::class);

        ServiceDefinition::forValue('service', new stdClass());
    }

    public function testObjectConstructorArgumentIsRejected(): void
    {
        $this->expectException(PortableValueException::class);

        ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
            arguments: ['dependency' => new stdClass()],
        );
    }

    public function testAutowireDefinitionCannotBeReadAsLiteralValue(): void
    {
        $definition = ServiceDefinition::forAutowire(
            'service',
            'Application\\Service',
        );

        $this->expectException(LogicException::class);

        $definition->value();
    }
}
