<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Container\Container;
use Flint\Foundation\Application;
use Flint\Foundation\ServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ApplicationProviderTest extends TestCase
{
    public function testProviderReceivesTheApplicationContainer(): void
    {
        $application = new Application(__DIR__);

        $provider = new class () implements ServiceProvider {
            public ?Container $received = null;

            public function register(Container $container): void
            {
                $this->received = $container;
            }
        };

        $application->register($provider);

        self::assertSame(
            $application->container(),
            $provider->received,
        );
    }

    public function testProviderRegistrationsAreAvailableImmediately(): void
    {
        $application = new Application(__DIR__);
        $service = new stdClass();

        $provider = new class ($service) implements ServiceProvider {
            public function __construct(
                private readonly stdClass $service,
            ) {
            }

            public function register(Container $container): void
            {
                $container->instance('service', $this->service);
            }
        };

        $application->register($provider);

        self::assertSame(
            $service,
            $application->container()->get('service'),
        );
    }

    public function testLaterProviderCanReadEarlierRegistrations(): void
    {
        $application = new Application(__DIR__);

        $first = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
                $container->instance('prefix', 'Flint');
            }
        };

        $second = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
                $prefix = $container->get('prefix');

                if (!is_string($prefix)) {
                    throw new RuntimeException(
                        'The prefix must be a string.',
                    );
                }

                $container->instance('label', $prefix . ' application');
            }
        };

        $application->register($first);
        $application->register($second);

        self::assertSame(
            'Flint application',
            $application->container()->get('label'),
        );
    }

    public function testProviderFailurePropagatesWithoutRollback(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Provider registration failed.');

        $provider = new class ($failure) implements ServiceProvider {
            public function __construct(
                private readonly RuntimeException $failure,
            ) {
            }

            public function register(Container $container): void
            {
                $container->instance('registered.before.failure', 'retained');

                throw $this->failure;
            }
        };

        try {
            $application->register($provider);

            self::fail('The provider failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(
            'retained',
            $application->container()->get('registered.before.failure'),
        );
    }
}
