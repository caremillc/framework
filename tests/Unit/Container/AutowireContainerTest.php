<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Exception\FrameworkException;
use CareminateIntegration\Tests\Fixtures\Container\ConstructorService;
use Closure;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use TypeError;

final class AutowireContainerTest extends TestCase
{
    public function testAutowiringRequiresExplicitRegistration(): void
    {
        $container = new Container();

        self::assertFalse($container->has(stdClass::class));

        $this->expectException(EntryNotFoundException::class);

        $container->get(stdClass::class);
    }

    public function testClassWithoutConstructorIsTransientByDefault(): void
    {
        $container = new Container();
        $container->autowire('service', stdClass::class);

        self::assertTrue($container->has('service'));

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
    }

    public function testSharedAutowiringUsesTheExistingSingletonCache(): void
    {
        $container = new Container();
        $container->autowire('service', stdClass::class, shared: true);
        $container->alias('service.alias', 'service');

        $service = $container->get('service.alias');

        self::assertInstanceOf(stdClass::class, $service);
        self::assertSame($service, $container->get('service'));
        self::assertSame($service, $container->get('service.alias'));
    }

    public function testDependenciesCanBeRegisteredAfterAutowireRegistration(): void
    {
        $container = new Container();
        $dependency = new Container();

        $container->autowire('service', ConstructorService::class);

        self::assertTrue($container->has('service'));

        $container->register(ContainerInterface::class, $dependency);

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertNull($service->optional);
        self::assertSame('default', $service->label);
    }

    public function testDependencyAliasesAndRegisteredOptionalValuesAreUsed(): void
    {
        $container = new Container();
        $dependency = new Container();
        $optional = new stdClass();

        $container->register('resolver', $dependency);
        $container->alias(ContainerInterface::class, 'resolver');
        $container->register(stdClass::class, $optional);
        $container->autowire('service', ConstructorService::class);

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame($optional, $service->optional);
    }

    public function testRegisteredNullIsAcceptedForANullableDependency(): void
    {
        $container = new Container();

        $container->register(ContainerInterface::class, new Container());
        $container->register(stdClass::class, null);
        $container->autowire('service', ConstructorService::class);

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertNull($service->optional);
    }

    public function testMissingDependencyPreservesItsPathAndCanBeRetried(): void
    {
        $container = new Container();

        $container->autowire(
            'service',
            ConstructorService::class,
            shared: true,
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            ['service', ContainerInterface::class],
            $failure->dependencyPath(),
        );

        self::assertInstanceOf(
            EntryNotFoundException::class,
            $failure->getPrevious(),
        );

        $dependency = new Container();
        $container->register(ContainerInterface::class, $dependency);

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame($service, $container->get('service'));
    }

    public function testInvalidRegisteredValueDoesNotFallBackToDefault(): void
    {
        $container = new Container();

        $container->register(ContainerInterface::class, new Container());
        $container->register(stdClass::class, 'invalid');
        $container->autowire('service', ConstructorService::class);

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            'The requested entry could not be resolved.',
            $failure->getMessage(),
        );

        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
    }

    public function testAutowiringParticipatesInCircularDependencyDiagnostics(): void
    {
        $container = new Container();

        $container->factory(
            ContainerInterface::class,
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        $container->autowire('service', ConstructorService::class);

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertSame(
            ['service', ContainerInterface::class, 'service'],
            $failure->dependencyPath(),
        );

        $container->autowire('unrelated', stdClass::class);

        self::assertInstanceOf(stdClass::class, $container->get('unrelated'));
    }

    #[DataProvider('unsupportedClasses')]
    public function testInvalidRegistrationDoesNotReserveTheIdentifier(
        string $class,
    ): void {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container, $class): void {
                $container->autowire('service', $class);
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

        self::assertSame([], $failure->dependencyPath());
        self::assertFalse($container->has('service'));

        $container->register('service', 'replacement');

        self::assertSame('replacement', $container->get('service'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedClasses(): iterable
    {
        yield 'unknown class' => [
            'CareminateIntegration\\Tests\\Fixtures\\MissingAutowireClass',
        ];

        yield 'abstract class' => [FrameworkException::class];
        yield 'interface' => [ContainerInterface::class];
        yield 'required primitive' => [DateTimeZone::class];
    }

    public function testDuplicateRegistrationPreservesTheOriginalEntry(): void
    {
        $container = new Container();
        $original = new stdClass();

        $container->register('service', $original);

        try {
            $container->autowire('service', stdClass::class);
        } catch (DuplicateEntryException) {
            self::assertSame($original, $container->get('service'));

            return;
        }

        self::fail('Autowiring must reject an existing identifier.');
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->autowire('', stdClass::class);
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
