<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ContainerException;
use Careminate\Container\Exception\NotFoundException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AliasContainerTest extends TestCase
{
    public function testAliasResolvesRegisteredInstance(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->instance('service', $service);
        $container->alias('alternate', 'service');

        self::assertSame($service, $container->get('alternate'));
    }

    public function testAliasPreservesSingletonIdentity(): void
    {
        $container = new Container();

        $container->singleton(
            'service',
            static fn (Container $container): object => new stdClass()
        );

        $container->alias('alternate', 'service');

        self::assertSame(
            $container->get('service'),
            $container->get('alternate')
        );
    }

    public function testAliasPreservesTransientBehavior(): void
    {
        $container = new Container();

        $container->bind(
            'service',
            static fn (Container $container): object => new stdClass()
        );

        $container->alias('alternate', 'service');

        self::assertNotSame(
            $container->get('alternate'),
            $container->get('alternate')
        );
    }

    public function testAliasChainResolvesTarget(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->instance('service', $service);
        $container->alias('first', 'second');
        $container->alias('second', 'service');

        self::assertSame($service, $container->get('first'));
    }

    public function testAliasCanBeRegisteredBeforeTarget(): void
    {
        $container = new Container();
        $service = new stdClass();

        $container->alias('alternate', 'service');
        $container->instance('service', $service);

        self::assertSame($service, $container->get('alternate'));
    }


    public function testAliasAvailabilityRequiresRegisteredTarget(): void
    {
        $container = new Container();

        $container->alias('alternate', 'service');

        self::assertFalse($container->has('alternate'));
        self::assertFalse($container->has('service'));

        $container->instance('service', new stdClass());

        self::assertTrue($container->has('alternate'));
        self::assertTrue($container->has('service'));
    }


    public function testMissingAliasTargetThrowsNotFound(): void
    {
        $container = new Container();

        $container->alias('alternate', 'missing');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(
            'Service "missing" is not registered.'
        );

        $container->get('alternate');
    }

    public function testSelfReferencingAliasIsRejected(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);

        $container->alias('service', 'service');
    }

    public function testAliasCycleIsDetected(): void
    {
        $container = new Container();

        $container->alias('first', 'second');
        $container->alias('second', 'first');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular service alias detected'
        );

        $container->get('first');
    }

    public function testDuplicateAliasIsRejected(): void
    {
        $container = new Container();

        $container->alias('alternate', 'service');

        $this->expectException(InvalidArgumentException::class);

        $container->alias('alternate', 'another');
    }

    public function testAliasCannotReplaceExistingInstance(): void
    {
        $container = new Container();

        $container->instance('service', new stdClass());

        $this->expectException(InvalidArgumentException::class);

        $container->alias('service', 'another');
    }

    public function testEmptyAliasTargetIsRejected(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);

        $container->alias('alternate', ' ');
    }
}
