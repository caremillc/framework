<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Contracts\Container\FactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;

interface BindingClock
{
}

final class SystemBindingClock implements BindingClock
{
}

final class FactoryProduct
{
    public function __construct(public readonly string $name)
    {
    }
}

final class ProductFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): mixed
    {
        unset($container);

        return new FactoryProduct('factory-service');
    }
}

final class BindingTest extends TestCase
{
    public function testClassBindingAndAliasAreResolved(): void
    {
        $container = new Container();

        $container->bind(BindingClock::class, SystemBindingClock::class);
        $container->alias('clock', BindingClock::class);

        self::assertInstanceOf(SystemBindingClock::class, $container->get('clock'));
        self::assertTrue($container->has(BindingClock::class));
    }

    public function testCallableFactoryReceivesContainer(): void
    {
        $container = new Container();

        $container->factory(
            FactoryProduct::class,
            static function (ContainerInterface $container): FactoryProduct {
                self::assertInstanceOf(Container::class, $container);

                return new FactoryProduct('callable');
            },
        );

        $product = $container->get(FactoryProduct::class);

        self::assertInstanceOf(FactoryProduct::class, $product);
        self::assertSame('callable', $product->name);
    }

    public function testFactoryServiceIsResolvedThroughContainer(): void
    {
        $container = new Container();

        $container->factory(FactoryProduct::class, ProductFactory::class);

        $product = $container->get(FactoryProduct::class);

        self::assertInstanceOf(FactoryProduct::class, $product);
        self::assertSame('factory-service', $product->name);
    }

    public function testInstanceBindingCanContainNull(): void
    {
        $container = new Container();
        $container->instance('nullable.value', null);

        self::assertTrue($container->has('nullable.value'));
        self::assertNull($container->get('nullable.value'));
    }

    public function testPsrContainerCanBeInjected(): void
    {
        $container = new Container();

        self::assertSame(
            $container,
            $container->get(PsrContainerInterface::class),
        );
    }

    public function testTagsResolveInRegistrationOrder(): void
    {
        $container = new Container();

        $container->instance('handler.first', 'first');
        $container->instance('handler.second', 'second');
        $container->tag(
            ['handler.first', 'handler.second'],
            'handlers',
        );

        self::assertSame(
            ['first', 'second'],
            [...$container->tagged('handlers')],
        );
    }
}
