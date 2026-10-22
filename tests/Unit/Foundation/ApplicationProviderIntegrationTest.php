<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Container\Container;
use Careminate\Foundation\Application;
use Careminate\Foundation\ServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ApplicationProviderIntegrationTest extends TestCase
{
    public function testProviderRegistersAndBootsBeforeApplicationCallbacks(): void
    {
        $events = new ApplicationProviderEvents();
        $application = new Application();

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ApplicationProviderEvents $events,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('provider:register');
            }

            public function boot(Container $container): void
            {
                $this->events->record('provider:boot');
            }
        };

        $application->registerProvider($provider);

        $application->onBoot(
            static function (Application $app) use ($events): void {
                $events->record('application:boot');
            }
        );

        $application->boot();

        self::assertSame(
            [
                'provider:register',
                'provider:boot',
                'application:boot',
            ],
            $events->all()
        );

        self::assertTrue($application->isBooted());
    }

    public function testAllProvidersRegisterBeforeAnyProviderBoots(): void
    {
        $events = new ApplicationProviderEvents();
        $application = new Application();

        $service = new stdClass();

        $first = new class ($events, $service) implements ServiceProvider {
            public function __construct(
                private readonly ApplicationProviderEvents $events,
                private readonly stdClass $service,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('first:register');
            }

            public function boot(Container $container): void
            {
                if ($container->get('shared.service') !== $this->service) {
                    throw new RuntimeException(
                        'Shared service was not registered.'
                    );
                }

                $this->events->record('first:boot');
            }
        };

        $second = new class ($events, $service) implements ServiceProvider {
            public function __construct(
                private readonly ApplicationProviderEvents $events,
                private readonly stdClass $service,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('second:register');

                $container->instance('shared.service', $this->service);
            }

            public function boot(Container $container): void
            {
                $this->events->record('second:boot');
            }
        };

        $application->registerProvider($first);
        $application->registerProvider($second);

        $application->boot();

        self::assertSame(
            [
                'first:register',
                'second:register',
                'first:boot',
                'second:boot',
            ],
            $events->all()
        );

        self::assertSame(
            $service,
            $application->container()->get('shared.service')
        );
    }

    public function testProviderCannotBeRegisteredAfterBoot(): void
    {
        $application = new Application();
        $application->boot();

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
            }

            public function boot(Container $container): void
            {
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Service providers must be registered before booting.'
        );

        $application->registerProvider($provider);
    }

    public function testDuplicateProviderRegistrationIsRejected(): void
    {
        $application = new Application();

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
            }

            public function boot(Container $container): void
            {
            }
        };

        $application->registerProvider($provider);

        $this->expectException(LogicException::class);

        $application->registerProvider(clone $provider);
    }

    public function testProviderRegistrationFailureMarksApplicationFailed(): void
    {
        $events = new ApplicationProviderEvents();
        $application = new Application();

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ApplicationProviderEvents $events,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('provider:register');

                throw new RuntimeException('Provider registration failed.');
            }

            public function boot(Container $container): void
            {
                $this->events->record('provider:boot');
            }
        };

        $application->registerProvider($provider);

        $application->onBoot(
            static function (Application $app) use ($events): void {
                $events->record('application:boot');
            }
        );

        try {
            $application->boot();

            self::fail('Expected provider registration failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Provider registration failed.',
                $exception->getMessage()
            );
        }

        self::assertSame(
            ['provider:register'],
            $events->all()
        );

        self::assertTrue($application->hasFailed());
        self::assertFalse($application->isBooted());

        $this->expectException(LogicException::class);

        $application->boot();
    }

    public function testProviderBootFailureMarksApplicationFailed(): void
    {
        $events = new ApplicationProviderEvents();
        $application = new Application();

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ApplicationProviderEvents $events,
            ) {
            }

            public function register(Container $container): void
            {
                $this->events->record('provider:register');
            }

            public function boot(Container $container): void
            {
                $this->events->record('provider:boot');

                throw new RuntimeException('Provider boot failed.');
            }
        };

        $application->registerProvider($provider);

        $application->onBoot(
            static function (Application $app) use ($events): void {
                $events->record('application:boot');
            }
        );

        try {
            $application->boot();

            self::fail('Expected provider boot failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Provider boot failed.',
                $exception->getMessage()
            );
        }

        self::assertSame(
            [
                'provider:register',
                'provider:boot',
            ],
            $events->all()
        );

        self::assertTrue($application->hasFailed());
        self::assertFalse($application->isBooted());
    }

    public function testFailedProviderApplicationCanTerminate(): void
    {
        $events = new ApplicationProviderEvents();
        $application = new Application();

        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
                throw new RuntimeException('Registration failed.');
            }

            public function boot(Container $container): void
            {
            }
        };

        $application->registerProvider($provider);

        $application->onTerminate(
            static function (Application $app) use ($events): void {
                $events->record('application:terminate');
            }
        );

        try {
            $application->boot();
            self::fail('Expected provider failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Registration failed.',
                $exception->getMessage()
            );
        }

        $application->terminate();

        self::assertSame(
            ['application:terminate'],
            $events->all()
        );

        self::assertTrue($application->isTerminated());
    }

    public function testProvidersAreNotBootedTwice(): void
    {
        $events = new ApplicationProviderEvents();
        $application = new Application();

        $provider = new class ($events) implements ServiceProvider {
            public function __construct(
                private readonly ApplicationProviderEvents $events,
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

        $application->registerProvider($provider);

        $application->boot();
        $application->boot();

        self::assertSame(
            ['register', 'boot'],
            $events->all()
        );
    }
}

final class ApplicationProviderEvents
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
