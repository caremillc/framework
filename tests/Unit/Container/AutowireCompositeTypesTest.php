<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayIterator;
use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\CompositeService;
use Closure;
use Countable;
use Error;
use InvalidArgumentException;
use IteratorAggregate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use TypeError;

final class AutowireCompositeTypesTest extends TestCase
{
    #[DataProvider('unionValues')]
    public function testUnionAcceptsEitherDeclaredMember(
        int|string $choice,
    ): void {
        $container = new Container();
        $arguments = self::validArguments();
        $arguments['choice'] = $choice;

        $container->autowire(
            'service',
            CompositeService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $service);
        self::assertSame($choice, $service->choice);
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function unionValues(): iterable
    {
        yield 'integer' => [17];
        yield 'string' => ['seventeen'];
        yield 'numeric string retains its type' => ['17'];
    }

    public function testIntersectionAndDnfAcceptAnObjectMatchingBothInterfaces(): void
    {
        $container = new Container();
        $collection = new ArrayObject([1, 2]);
        $arguments = self::validArguments();
        $arguments['collection'] = $collection;
        $arguments['stream'] = $collection;
        $arguments['optional'] = $collection;

        $container->autowire(
            'service',
            CompositeService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $service);
        self::assertSame($collection, $service->collection);
        self::assertSame($collection, $service->stream);
        self::assertSame($collection, $service->optional);
    }

    public function testDnfAcceptsItsAlternativeClass(): void
    {
        $container = new Container();
        $stream = new stdClass();
        $arguments = self::validArguments();
        $arguments['stream'] = $stream;
        $arguments['optional'] = $stream;

        $container->autowire(
            'service',
            CompositeService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $service);
        self::assertSame($stream, $service->stream);
        self::assertSame($stream, $service->optional);
    }

    public function testDefaultsDoNotResolveRegisteredCompositeMembers(): void
    {
        $container = new Container();

        foreach (
            [
                stdClass::class,
                ArrayObject::class,
                Countable::class,
                IteratorAggregate::class,
            ] as $identifier
        ) {
            $container->factory(
                $identifier,
                static function (ContainerInterface $resolver): never {
                    throw new Error('Composite members must not be resolved.');
                },
            );
        }

        $container->autowire(
            'service',
            CompositeService::class,
            arguments: self::validArguments(),
        );

        $service = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $service);
        self::assertSame('fallback', $service->fallback);
        self::assertNull($service->optional);
        self::assertInstanceOf(stdClass::class, $service->objectDefault);
    }

    public function testExplicitValuesOverrideCompositeDefaults(): void
    {
        $container = new Container();
        $object = new ArrayObject([3]);
        $arguments = self::validArguments();
        $arguments['fallback'] = 0;
        $arguments['optional'] = null;
        $arguments['objectDefault'] = $object;

        $container->autowire(
            'service',
            CompositeService::class,
            arguments: $arguments,
        );

        $service = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $service);
        self::assertSame(0, $service->fallback);
        self::assertNull($service->optional);
        self::assertSame($object, $service->objectDefault);
    }

    public function testObjectDefaultIsCreatedForEachTransientConstruction(): void
    {
        $container = new Container();

        $container->autowire(
            'service',
            CompositeService::class,
            arguments: self::validArguments(),
        );

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $first);
        self::assertInstanceOf(CompositeService::class, $second);
        self::assertNotSame($first, $second);
        self::assertInstanceOf(stdClass::class, $first->objectDefault);
        self::assertInstanceOf(stdClass::class, $second->objectDefault);
        self::assertNotSame($first->objectDefault, $second->objectDefault);
    }

    public function testSharedCompositeServiceRetainsItsConstructedDefaults(): void
    {
        $container = new Container();

        $container->autowire(
            'service',
            CompositeService::class,
            shared: true,
            arguments: self::validArguments(),
        );

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(CompositeService::class, $first);
        self::assertInstanceOf(CompositeService::class, $second);
        self::assertSame($first, $second);
        self::assertSame($first->objectDefault, $second->objectDefault);
    }

    #[DataProvider('requiredCompositeParameters')]
    public function testMissingExplicitValueRejectsRegistration(
        string $parameter,
    ): void {
        $container = new Container();
        $collection = new ArrayObject([1]);
        $arguments = self::validArguments();

        unset($arguments[$parameter]);

        $container->register(Countable::class, $collection);
        $container->register(IteratorAggregate::class, $collection);
        $container->register(stdClass::class, new stdClass());

        $failure = self::captureFailure(
            static function () use ($container, $arguments): void {
                $container->autowire(
                    'service',
                    CompositeService::class,
                    arguments: $arguments,
                );
            },
        );

        self::assertSame(
            'The autowired entry could not be registered.',
            $failure->getMessage(),
        );

        $cause = $failure->getPrevious();

        self::assertInstanceOf(InvalidArgumentException::class, $cause);

        self::assertSame(
            'A composite parameter requires an explicit value or declared default.',
            $cause->getMessage(),
        );

        self::assertSame([], $failure->dependencyPath());
        self::assertFalse($container->has('service'));

        $container->register('service', 'replacement');

        self::assertSame('replacement', $container->get('service'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredCompositeParameters(): iterable
    {
        yield 'union' => ['choice'];
        yield 'intersection' => ['collection'];
        yield 'dnf' => ['stream'];
    }

    #[DataProvider('invalidCompositeValues')]
    public function testInvalidValueFailsDuringConstruction(
        string $parameter,
        mixed $value,
    ): void {
        $container = new Container();
        $arguments = self::validArguments();
        $arguments[$parameter] = $value;

        $container->autowire(
            'service',
            CompositeService::class,
            shared: true,
            arguments: $arguments,
        );

        self::assertTrue($container->has('service'));

        $first = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            'The requested entry could not be resolved.',
            $first->getMessage(),
        );

        self::assertSame(['service'], $first->dependencyPath());
        self::assertInstanceOf(TypeError::class, $first->getPrevious());

        $second = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(['service'], $second->dependencyPath());
        self::assertInstanceOf(TypeError::class, $second->getPrevious());

        $container->autowire('unrelated', stdClass::class);

        self::assertInstanceOf(stdClass::class, $container->get('unrelated'));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidCompositeValues(): iterable
    {
        yield 'union rejects boolean' => ['choice', true];
        yield 'union rejects null' => ['choice', null];

        yield 'intersection rejects unrelated object' => [
            'collection',
            new stdClass(),
        ];

        yield 'intersection requires every member' => [
            'collection',
            new ArrayIterator([1]),
        ];

        yield 'dnf requires the complete intersection' => [
            'stream',
            new ArrayIterator([1]),
        ];

        yield 'dnf rejects null when not declared' => ['stream', null];
        yield 'nullable dnf rejects integer' => ['optional', 1];
        yield 'invalid override does not use scalar default' => ['fallback', false];
        yield 'invalid override does not use object default' => ['objectDefault', null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validArguments(): array
    {
        return [
            'choice' => 'report',
            'collection' => new ArrayObject([1]),
            'stream' => new stdClass(),
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
