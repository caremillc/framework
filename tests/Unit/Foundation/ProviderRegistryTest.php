<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Container\Container;
use Careminate\Foundation\ProviderRegistry;
use Careminate\Foundation\ServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ProviderRegistryTest extends TestCase
{
    public function testEmptyRegistryCanBoot(): void
    {
        $registry = new ProviderRegistry(new Container());

        $registry->boot();

        self::assertTrue($registry->isRegistered());
        self::assertTrue($registry->isBooted());
        self::assertSame(0, $registry->count());
    }

    public function testAllProvidersRegisterBeforeAnyProviderBoots(): void
    {
        $events = new ProviderEventRecorder();
        $container = new Container();
        $registry = new ProviderRegistry($container);

        $firstService = new stdClass();
        $secondService = new stdClass();

        $first = new class ($events, $firstService, $secondService) implements ServiceProvider {
            public function __construct(
                private readonly ProviderEventRecorder $events,
                private readonly stdClass $firstService,
                private readonly stdClass $secondService,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('register:first');

                $container->instance('first', $this->firstService);
            }

            public function boot(Container $container): void
            {
                $this->events->record('boot:first');

                if ($container->get('second') !== $this->secondService) {
                    throw new RuntimeException(
                        'Second service was not registered before boot.'
                    );
                }

                $this->events->record('resolve:second');
            }
        };

        $second = new class ($events, $secondService) implements ServiceProvider {
            public function __construct(
                private readonly ProviderEventRecorder $events,
                private readonly stdClass $secondService,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('register:second');

                $container->instance('second', $this->secondService);
            }

            public function boot(Container $container): void
            {
                $this->events->record('boot:second');
            }
        };

        $registry->add($first);
        $registry->add($second);

        $registry->boot();

        self::assertSame(
            [
                'register:first',
                'register:second',
                'boot:first',
                'resolve:second',
                'boot:second',
            ],
            $events->all()
        );

        self::assertSame($firstService, $container->get('first'));
        self::assertSame($secondService, $container->get('second'));
        self::assertSame(2, $registry->count());
    }

    public function testRegistrationAndBootAreIdempotent(): void
    {
        $events = new ProviderEventRecorder();
        $registry = new ProviderRegistry(new Container());

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ProviderEventRecorder $events,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('register');
            }

            public function boot(Container $container): void
            {
                $this->events->record('boot');
            }
        };

        $registry->add($provider);

        $registry->register();
        $registry->register();

        $registry->boot();
        $registry->boot();

        self::assertSame(
            ['register', 'boot'],
            $events->all()
        );

        self::assertTrue($registry->isRegistered());
        self::assertTrue($registry->isBooted());
    }

    public function testDuplicateProviderClassIsRejected(): void
    {
        $registry = new ProviderRegistry(new Container());

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
            }

            public function boot(Container $container): void
            {
            }
        };

        $registry->add($provider);

        $this->expectException(LogicException::class);

        $registry->add(clone $provider);
    }

    public function testProviderCannotBeAddedAfterRegistration(): void
    {
        $registry = new ProviderRegistry(new Container());

        $registry->register();

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
            }

            public function boot(Container $container): void
            {
            }
        };

        $this->expectException(LogicException::class);

        $registry->add($provider);
    }

    public function testRegistrationFailurePreventsBoot(): void
    {
        $events = new ProviderEventRecorder();
        $registry = new ProviderRegistry(new Container());

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ProviderEventRecorder $events,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('register');

                throw new RuntimeException('Registration failed.');
            }

            public function boot(Container $container): void
            {
                $this->events->record('boot');
            }
        };

        $registry->add($provider);

        try {
            $registry->boot();

            self::fail('Expected registration failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Registration failed.',
                $exception->getMessage()
            );
        }

        self::assertSame(['register'], $events->all());
        self::assertFalse($registry->isRegistered());
        self::assertFalse($registry->isBooted());

        $this->expectException(LogicException::class);

        $registry->boot();
    }

    public function testBootFailureCannotBeRetried(): void
    {
        $events = new ProviderEventRecorder();
        $registry = new ProviderRegistry(new Container());

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ProviderEventRecorder $events,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('register');
            }

            public function boot(Container $container): void
            {
                $this->events->record('boot');

                throw new RuntimeException('Boot failed.');
            }
        };

        $registry->add($provider);

        try {
            $registry->boot();

            self::fail('Expected boot failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Boot failed.',
                $exception->getMessage()
            );
        }

        self::assertSame(['register', 'boot'], $events->all());
        self::assertTrue($registry->isRegistered());
        self::assertFalse($registry->isBooted());

        $this->expectException(LogicException::class);

        $registry->boot();
    }
}

final class ProviderEventRecorder
{
    /** @var list<string> */
    private array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->events;
    }
}
