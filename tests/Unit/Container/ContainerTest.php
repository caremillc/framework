<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ContainerException;
use Careminate\Container\Exception\NotFoundException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testTransientBindingCreatesNewInstances(): void
    {
        $container = new Container();

        $container->bind(
            'service',
            static fn (Container $container): object => new stdClass()
        );

        self::assertNotSame(
            $container->get('service'),
            $container->get('service')
        );
    }

    public function testSingletonRetainsInstance(): void
    {
        $container = new Container();

        $container->singleton(
            'service',
            static fn (Container $container): object => new stdClass()
        );

        self::assertSame(
            $container->get('service'),
            $container->get('service')
        );
    }

    public function testRegisteredInstancePreservesIdentity(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->instance('service', $service);

        self::assertSame($service, $container->get('service'));
    }

    public function testHasReportsRegistrationState(): void
    {
        $container = new Container();

        self::assertFalse($container->has('service'));

        $container->bind(
            'service',
            static fn (Container $container): object => new stdClass()
        );

        self::assertTrue($container->has('service'));
    }

    public function testFactoryReceivesContainer(): void
    {
        $container = new Container();
        $dependency = new stdClass();

        $container->instance('dependency', $dependency);

        $container->bind(
            'service',
            static fn (Container $container): object =>
                $container->get('dependency')
        );

        self::assertSame(
            $dependency,
            $container->get('service')
        );
    }

    public function testMissingServiceThrowsException(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(
            'Service "missing" is not registered.'
        );

        $container->get('missing');
    }

    public function testDuplicateRegistrationIsRejected(): void
    {
        $container = new Container();

        $container->instance('service', new stdClass());

        $this->expectException(InvalidArgumentException::class);

        $container->bind(
            'service',
            static fn (Container $container): object => new stdClass()
        );
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);

        $container->instance(' ', new stdClass());
    }

    public function testCircularDependencyIsDetected(): void
    {
        $container = new Container();

        $container->bind(
            'first',
            static fn (Container $container): object =>
                $container->get('second')
        );

        $container->bind(
            'second',
            static fn (Container $container): object =>
                $container->get('first')
        );

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected'
        );

        $container->get('first');
    }

    public function testFailedSingletonCanBeRetried(): void
    {
        $container = new Container();
        $attempts = 0;

        $container->singleton(
            'service',
            static function (Container $container) use (&$attempts): object {
                ++$attempts;

                if ($attempts === 1) {
                    throw new ContainerException('Temporary failure.');
                }

                return new stdClass();
            }
        );

        try {
            $container->get('service');
            self::fail('First resolution should fail.');
        } catch (ContainerException $exception) {
            self::assertSame(
                'Temporary failure.',
                $exception->getMessage()
            );
        }

        $resolved = $container->get('service');

        self::assertSame(2, $attempts);
        self::assertSame($resolved, $container->get('service'));
    }
}
