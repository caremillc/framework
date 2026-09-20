<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Module\Internal\OwnedServiceRegistry;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ServiceLifetime;
use Careminate\Module\ServiceProviderInterface;
use Careminate\Module\ServiceRegistryInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OwnedServiceRegistryTest extends TestCase
{
    public function testProviderContributionsCarryTheirAssignedOwner(): void
    {
        $owner = new ModuleIdentifier('billing');
        $registry = new OwnedServiceRegistry($owner);

        $provider = new class () implements ServiceProviderInterface {
            public function register(ServiceRegistryInterface $services): void
            {
                $services->value('billing.currency', 'USD');
                $services->value('billing.optional', null);
            }
        };

        $provider->register($registry);
        $snapshot = $registry->seal();

        self::assertSame($owner, $snapshot->owner);
        self::assertCount(2, $snapshot->definitions->services);
        self::assertSame(
            'billing.currency',
            $snapshot->definitions->services[0]->id,
        );

        $container = new CompiledDefinitionContainerFactory()->create(
            $snapshot->definitions,
        );

        self::assertSame('USD', $container->get('billing.currency'));
        self::assertTrue($container->has('billing.optional'));
        self::assertNull($container->get('billing.optional'));
    }

    #[DataProvider('lifetimes')]
    public function testPublicLifetimesMapToCompilationLifetimes(
        ServiceLifetime $lifetime,
        DefinitionLifetime $expected,
    ): void {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));

        $registry->autowire('service', stdClass::class, $lifetime);

        $snapshot = $registry->seal();
        $definition = $snapshot->definitions->services[0];

        self::assertSame('service', $definition->id);
        self::assertSame(stdClass::class, $definition->className);
        self::assertSame($expected, $definition->lifetime);
        self::assertFalse($definition->lazy);
    }

    public function testConstructorArgumentsAndLazyFlagArePreserved(): void
    {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));

        // Definition recording does not inspect or instantiate the class.
        $registry->autowire(
            'service',
            'Application\\BillingService',
            ServiceLifetime::Singleton,
            arguments: ['currency' => 'USD'],
            lazy: true,
        );

        $definition = $registry->seal()->definitions->services[0];

        self::assertSame(['currency' => 'USD'], $definition->arguments());
        self::assertTrue($definition->lazy);
    }

    public function testLazyTransientRegistrationIsRejected(): void
    {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));

        $this->expectException(DefinitionException::class);

        $registry->autowire('service', stdClass::class, lazy: true);
    }

    public function testDuplicateIdentifierCannotReplaceRegisteredNull(): void
    {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));
        $registry->value('service', null);

        try {
            $registry->autowire('service', stdClass::class);
        } catch (DefinitionException $exception) {
            self::assertSame(
                'A registration identifier is already defined.',
                $exception->getMessage(),
            );

            $definitions = $registry->seal()->definitions;

            self::assertCount(1, $definitions->services);
            self::assertNull($definitions->services[0]->value());

            return;
        }

        self::fail('Duplicate registration must be rejected.');
    }

    public function testFailedValidationDoesNotReserveTheIdentifier(): void
    {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));

        try {
            $registry->autowire('service', '');
        } catch (DefinitionException $exception) {
            self::assertSame(
                'An autowired definition requires a non-empty class name.',
                $exception->getMessage(),
            );

            $registry->value('service', 'recovered');

            self::assertSame(
                'recovered',
                $registry->seal()->definitions->services[0]->value(),
            );

            return;
        }

        self::fail('An empty class name must be rejected.');
    }

    public function testSealingAnEmptyRegistryIsIdempotent(): void
    {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));
        $first = $registry->seal();

        self::assertSame($first, $registry->seal());
        self::assertSame([], $first->definitions->services);
    }

    #[DataProvider('registrationKinds')]
    public function testSealedRegistryRejectsFurtherRegistration(
        bool $autowired,
    ): void {
        $registry = new OwnedServiceRegistry(new ModuleIdentifier('billing'));
        $registry->seal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The module service registry is sealed.');

        if ($autowired) {
            $registry->autowire('late', stdClass::class);
        } else {
            $registry->value('late', null);
        }
    }

    /**
     * @return iterable<string, array{ServiceLifetime, DefinitionLifetime}>
     */
    public static function lifetimes(): iterable
    {
        yield 'transient' => [
            ServiceLifetime::Transient,
            DefinitionLifetime::Transient,
        ];

        yield 'singleton' => [
            ServiceLifetime::Singleton,
            DefinitionLifetime::Singleton,
        ];

        yield 'scoped' => [
            ServiceLifetime::Scoped,
            DefinitionLifetime::Scoped,
        ];
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function registrationKinds(): iterable
    {
        yield 'literal value' => [false];
        yield 'autowired service' => [true];
    }
}
