<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Attribute\Inject;
use Careminate\Container\Attribute\Lazy;
use Careminate\Container\Attribute\Tagged;
use Careminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

interface AttributeClock
{
}

final class UtcAttributeClock implements AttributeClock
{
}

final class AutowiredService
{
    public function __construct(public readonly AttributeClock $clock)
    {
    }
}

final class NamedApplication
{
    public function __construct(
        #[Inject('application.name')]
        public readonly string $name,
    ) {
    }
}

final class HandlerCollection
{
    /**
     * @param iterable<object> $handlers
     */
    public function __construct(
        #[Tagged('message.handlers')]
        public readonly iterable $handlers,
    ) {
    }
}

final class FirstHandler
{
}

final class SecondHandler
{
}

#[Lazy]
final class LazyConnection
{
    public static int $constructed = 0;

    public function __construct(private readonly string $dsn = 'sqlite::memory:')
    {
        ++self::$constructed;
    }

    public function dsn(): string
    {
        return $this->dsn;
    }
}

final class AttributesAndAutowiringTest extends TestCase
{
    protected function setUp(): void
    {
        LazyConnection::$constructed = 0;
    }

    public function testConstructorDependenciesAreAutowired(): void
    {
        $container = new Container();
        $container->bind(AttributeClock::class, UtcAttributeClock::class);

        $service = $container->get(AutowiredService::class);

        self::assertInstanceOf(UtcAttributeClock::class, $service->clock);
    }

    public function testInjectAttributeSelectsNamedEntry(): void
    {
        $container = new Container();
        $container->instance('application.name', 'Caremi');

        $application = $container->get(NamedApplication::class);

        self::assertSame('Caremi', $application->name);
    }

    public function testTaggedAttributeInjectsAllTaggedEntries(): void
    {
        $container = new Container();

        $container->singleton(FirstHandler::class);
        $container->singleton(SecondHandler::class);
        $container->tag(
            [FirstHandler::class, SecondHandler::class],
            'message.handlers',
        );

        $collection = $container->get(HandlerCollection::class);

        self::assertContainsOnlyInstancesOf(
            FirstHandler::class,
            [$container->get(FirstHandler::class)],
        );

        self::assertCount(2, [...$collection->handlers]);
    }

    public function testLazyAttributeDefersConstructor(): void
    {
        $container = new Container();

        $connection = $container->get(LazyConnection::class);

        self::assertInstanceOf(LazyConnection::class, $connection);
        self::assertSame(0, LazyConnection::$constructed);

        $reflection = new ReflectionClass(LazyConnection::class);

        self::assertTrue(
            $reflection->isUninitializedLazyObject($connection),
        );

        self::assertSame('sqlite::memory:', $connection->dsn());
        self::assertSame(1, LazyConnection::$constructed);
    }
}
