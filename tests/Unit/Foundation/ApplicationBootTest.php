<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Closure;
use Flint\Container\Container;
use Flint\Foundation\Application;
use Flint\Foundation\BootableServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationBootTest extends TestCase
{
    public function testBootRunsInRegistrationOrderAfterRegistration(): void
    {
        $application = new Application(__DIR__);
        $events = [];

        foreach (['first', 'second'] as $name) {
            $application->register($this->provider(
                static function (Container $container) use (
                    &$events,
                    $name,
                ): void {
                    $events[] = 'register:' . $name;
                    $container->instance($name, $name);
                },
                static function (Container $container) use (
                    &$events,
                    $name,
                ): void {
                    self::assertSame('second', $container->get('second'));
                    $events[] = 'boot:' . $name;
                },
            ));
        }

        self::assertFalse($application->isBooted());
        self::assertSame(
            ['register:first', 'register:second'],
            $events,
        );

        $application->boot();
        $application->boot();

        self::assertTrue($application->isBooted());
        self::assertSame(
            [
                'register:first',
                'register:second',
                'boot:first',
                'boot:second',
            ],
            $events,
        );
    }

    public function testRegistrationAfterBootIsRejected(): void
    {
        $application = new Application(__DIR__);
        $application->boot();

        $provider = $this->provider(
            static function (Container $container): void {
                self::fail('Late registration must not run.');
            },
            static function (Container $container): void {
            },
        );

        $this->expectException(LogicException::class);

        $application->register($provider);
    }

    public function testBootFailureStopsLaterProvidersAndCannotBeRetried(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Boot failed.');
        $attempts = 0;

        $application->register($this->provider(
            static function (Container $container): void {
            },
            static function (Container $container) use (
                $failure,
                &$attempts,
            ): void {
                ++$attempts;

                throw $failure;
            },
        ));

        $application->register($this->provider(
            static function (Container $container): void {
            },
            static function (Container $container): void {
                self::fail('Later providers must not boot after failure.');
            },
        ));

        try {
            $application->boot();

            self::fail('Boot must propagate the original failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($application->isBooted());

        try {
            $application->boot();

            self::fail('A failed application must not boot again.');
        } catch (LogicException) {
            self::assertSame(1, $attempts);
        }
    }

    public function testRegistrationFailurePreventsBoot(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Registration failed.');

        try {
            $application->register($this->provider(
                static function (Container $container) use ($failure): void {
                    throw $failure;
                },
                static function (Container $container): void {
                    self::fail('Failed registration must not reach boot.');
                },
            ));

            self::fail('Registration must propagate the original failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($application->isBooted());

        $this->expectException(LogicException::class);

        $application->boot();
    }

    public function testBootDuringRegistrationIsRejected(): void
    {
        $application = new Application(__DIR__);
        $rejected = false;

        $application->register($this->provider(
            static function (Container $container) use (
                $application,
                &$rejected,
            ): void {
                try {
                    $application->boot();
                } catch (LogicException) {
                    $rejected = true;
                }
            },
            static function (Container $container): void {
            },
        ));

        self::assertTrue($rejected);
        self::assertFalse($application->isBooted());

        $application->boot();

        self::assertTrue($application->isBooted());
    }

    public function testReentrantBootIsRejectedWithoutRepeatingCallbacks(): void
    {
        $application = new Application(__DIR__);
        $attempts = 0;
        $rejected = false;

        $application->register($this->provider(
            static function (Container $container): void {
            },
            static function (Container $container) use (
                $application,
                &$attempts,
                &$rejected,
            ): void {
                ++$attempts;

                // Guard against uncontrolled recursion if this regresses.
                if ($attempts > 1) {
                    self::fail('Boot callbacks must not be reentered.');
                }

                try {
                    $application->boot();
                } catch (LogicException) {
                    $rejected = true;
                }
            },
        ));

        $application->boot();

        self::assertTrue($rejected);
        self::assertTrue($application->isBooted());
        self::assertSame(1, $attempts);
    }

    public function testProviderRegistrationDuringBootIsRejected(): void
    {
        $application = new Application(__DIR__);
        $rejected = false;

        $lateProvider = $this->provider(
            static function (Container $container): void {
                self::fail('Registration during boot must not execute.');
            },
            static function (Container $container): void {
                self::fail('A rejected provider must not boot.');
            },
        );

        $application->register($this->provider(
            static function (Container $container): void {
            },
            static function (Container $container) use (
                $application,
                $lateProvider,
                &$rejected,
            ): void {
                try {
                    $application->register($lateProvider);
                } catch (LogicException) {
                    $rejected = true;
                }
            },
        ));

        $application->boot();

        self::assertTrue($rejected);
        self::assertTrue($application->isBooted());
    }

    /**
     * @param Closure(Container): void $register
     * @param Closure(Container): void $boot
     */
    private function provider(
        Closure $register,
        Closure $boot,
    ): BootableServiceProvider {
        return new class ($register, $boot) implements BootableServiceProvider {
            /**
             * @param Closure(Container): void $registerCallback
             * @param Closure(Container): void $bootCallback
             */
            public function __construct(
                private readonly Closure $registerCallback,
                private readonly Closure $bootCallback,
            ) {
            }

            public function register(Container $container): void
            {
                ($this->registerCallback)($container);
            }

            public function boot(Container $container): void
            {
                ($this->bootCallback)($container);
            }
        };
    }
}
