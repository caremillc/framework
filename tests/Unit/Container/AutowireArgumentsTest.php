<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\ArgumentService;
use Closure;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use TypeError;

final class AutowireArgumentsTest extends TestCase
{
    public function testRequiredPrimitivesAcceptExplicitFalsyValues(): void
    {
        $container = new Container();

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: self::validArguments(),
        );

        $service = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertSame('', $service->name);
        self::assertSame(0, $service->limit);
        self::assertFalse($service->enabled);
        self::assertNull($service->dependency);
        self::assertSame('default', $service->label);
        self::assertNull($service->payload);
    }

    public function testArgumentOrderDoesNotDetermineConstructorOrder(): void
    {
        $container = new Container();

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: [
                'label' => 'custom',
                'dependency' => null,
                'enabled' => true,
                'limit' => 12,
                'name' => 'report',
            ],
        );

        $service = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertSame('report', $service->name);
        self::assertSame(12, $service->limit);
        self::assertTrue($service->enabled);
        self::assertSame('custom', $service->label);
    }

    public function testExplicitObjectBypassesRegisteredDependencyFactory(): void
    {
        $container = new Container();
        $dependency = new stdClass();
        $arguments = self::validArguments();
        $arguments['dependency'] = $dependency;

        $container->factory(
            stdClass::class,
            static function (ContainerInterface $resolver): never {
                throw new Error('The overridden dependency must not be resolved.');
            },
        );

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertSame($dependency, $service->dependency);
    }

    public function testExplicitNullOverridesARegisteredObject(): void
    {
        $container = new Container();

        $container->register(stdClass::class, new stdClass());

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: self::validArguments(),
        );

        $service = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertNull($service->dependency);
    }

    public function testOmittedObjectArgumentUsesRegisteredDependency(): void
    {
        $container = new Container();
        $dependency = new stdClass();
        $arguments = self::validArguments();

        unset($arguments['dependency']);

        $container->register(stdClass::class, $dependency);

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertSame($dependency, $service->dependency);
    }

    public function testNullableDependencyWithoutDefaultStillRequiresResolution(): void
    {
        $container = new Container();
        $arguments = self::validArguments();

        unset($arguments['dependency']);

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: $arguments,
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            ['service', stdClass::class],
            $failure->dependencyPath(),
        );

        self::assertInstanceOf(
            EntryNotFoundException::class,
            $failure->getPrevious(),
        );
    }

    public function testClosureArgumentIsPassedAsALiteralValue(): void
    {
        $container = new Container();
        $callback = static fn (): string => 'callback result';
        $arguments = self::validArguments();
        $arguments['payload'] = $callback;

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertSame($callback, $service->payload);
    }

    public function testTransientInstancesRetainExplicitObjectIdentity(): void
    {
        $container = new Container();
        $dependency = new stdClass();
        $arguments = self::validArguments();
        $arguments['dependency'] = $dependency;

        $container->autowire(
            'service',
            ArgumentService::class,
            false,
            $arguments,
        );

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(ArgumentService::class, $first);
        self::assertInstanceOf(ArgumentService::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame($dependency, $first->dependency);
        self::assertSame($dependency, $second->dependency);
    }

    public function testSharedRegistrationWithArgumentsCachesTheInstance(): void
    {
        $container = new Container();

        $container->autowire(
            'service',
            ArgumentService::class,
            shared: true,
            arguments: self::validArguments(),
        );

        $container->alias('service.alias', 'service');

        $service = $container->get('service.alias');

        self::assertInstanceOf(ArgumentService::class, $service);
        self::assertSame($service, $container->get('service'));
        self::assertSame($service, $container->get('service.alias'));
    }

    /**
     * @param array<array-key, mixed> $extraArguments
     */
    #[DataProvider('invalidArgumentNames')]
    public function testInvalidArgumentNamesDoNotReserveTheIdentifier(
        array $extraArguments,
    ): void {
        $container = new Container();
        $arguments = array_replace(
            self::validArguments(),
            $extraArguments,
        );

        $failure = self::captureFailure(
            static function () use ($container, $arguments): void {
                $container->autowire(
                    'service',
                    ArgumentService::class,
                    arguments: $arguments,
                );
            },
        );

        self::assertSame(
            'The autowired entry could not be registered.',
            $failure->getMessage(),
        );

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));

        $container->register('service', 'replacement');

        self::assertSame('replacement', $container->get('service'));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidArgumentNames(): iterable
    {
        yield 'unknown name' => [['unknown' => 'value']];
        yield 'incorrect case' => [['Name' => 'value']];
        yield 'empty name' => [['' => 'value']];
        yield 'numeric key' => [[0 => 'value']];
    }

    public function testClassWithoutConstructorRejectsExplicitArguments(): void
    {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->autowire(
                    'service',
                    stdClass::class,
                    arguments: ['name' => 'value'],
                );
            },
        );

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));
    }

    public function testRequiredPrimitiveWithoutOverrideStillFailsRegistration(): void
    {
        $container = new Container();
        $arguments = self::validArguments();

        unset($arguments['name']);

        $failure = self::captureFailure(
            static function () use ($container, $arguments): void {
                $container->autowire(
                    'service',
                    ArgumentService::class,
                    arguments: $arguments,
                );
            },
        );

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));
    }

    #[DataProvider('invalidArgumentValues')]
    public function testInvalidValuesFailLazilyWithTheirOriginalTypeError(
        string $name,
        mixed $value,
    ): void {
        $container = new Container();
        $arguments = self::validArguments();
        $arguments[$name] = $value;

        $container->autowire(
            'service',
            ArgumentService::class,
            arguments: $arguments,
        );

        self::assertTrue($container->has('service'));

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            'The requested entry could not be resolved.',
            $failure->getMessage(),
        );

        self::assertSame(['service'], $failure->dependencyPath());
        self::assertInstanceOf(TypeError::class, $failure->getPrevious());

        $container->autowire('unrelated', stdClass::class);

        self::assertInstanceOf(stdClass::class, $container->get('unrelated'));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidArgumentValues(): iterable
    {
        yield 'numeric string for integer' => ['limit', '12'];
        yield 'integer for boolean' => ['enabled', 1];
        yield 'null for required string' => ['name', null];
        yield 'string for object' => ['dependency', 'invalid'];
        yield 'null for defaulted non-nullable string' => ['label', null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validArguments(): array
    {
        return [
            'name' => '',
            'limit' => 0,
            'enabled' => false,
            'dependency' => null,
        ];
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
