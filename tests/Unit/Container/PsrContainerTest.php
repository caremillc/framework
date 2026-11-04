<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ContainerException;
use Careminate\Container\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;

final class PsrContainerTest extends TestCase
{
    public function testContainerImplementsPsrInterface(): void
    {
        self::assertInstanceOf(
            ContainerInterface::class,
            new Container()
        );
    }

    public function testContainerExceptionImplementsPsrInterface(): void
    {
        self::assertInstanceOf(
            ContainerExceptionInterface::class,
            new ContainerException('Failure.')
        );
    }

    public function testNotFoundExceptionImplementsPsrInterface(): void
    {
        $exception = new NotFoundException('missing');

        self::assertInstanceOf(
            NotFoundExceptionInterface::class,
            $exception
        );

        self::assertInstanceOf(
            ContainerExceptionInterface::class,
            $exception
        );
    }

    public function testHasReturnsFalseForMissingAliasTarget(): void
    {
        $container = new Container();

        $container->alias('alias', 'missing');

        self::assertFalse($container->has('alias'));
    }

    public function testHasReturnsTrueForResolvedAlias(): void
    {
        $container = new Container();

        $container->instance('service', new stdClass());
        $container->alias('alias', 'service');

        self::assertTrue($container->has('alias'));
    }

    public function testHasReturnsFalseForAliasCycle(): void
    {
        $container = new Container();

        $container->alias('first', 'second');
        $container->alias('second', 'first');

        self::assertFalse($container->has('first'));
        self::assertFalse($container->has('second'));
    }

    public function testMissingServiceThrowsPsrNotFound(): void
    {
        $container = new Container();

        $this->expectException(
            NotFoundExceptionInterface::class
        );

        $container->get('missing');
    }

    public function testFactoryFailureIsWrapped(): void
    {
        $container = new Container();

        $container->bind(
            'broken',
            static function (Container $container): object {
                throw new RuntimeException('Original failure.');
            }
        );

        try {
            $container->get('broken');
            self::fail('Expected a container exception.');
        } catch (ContainerException $exception) {
            self::assertSame(
                'Failed to resolve service "broken".',
                $exception->getMessage()
            );

            self::assertInstanceOf(
                RuntimeException::class,
                $exception->getPrevious()
            );

            self::assertSame(
                'Original failure.',
                $exception->getPrevious()->getMessage()
            );
        }
    }

    public function testNestedMissingDependencyRemainsNotFound(): void
    {
        $container = new Container();

        $container->bind(
            'service',
            static fn (Container $container): object =>
                $container->get('missing')
        );

        $this->expectException(
            NotFoundExceptionInterface::class
        );

        $container->get('service');
    }

    public function testHasDoesNotExecuteFactories(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind(
            'service',
            static function (Container $container) use (&$calls): object {
                ++$calls;

                return new stdClass();
            }
        );

        self::assertTrue($container->has('service'));
        self::assertSame(0, $calls);
    }
}
