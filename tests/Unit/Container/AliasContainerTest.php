<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Container;

use Flint\Container\Container;
use Flint\Container\Exception\ContainerException;
use Flint\Container\Exception\EntryNotFoundException;
use Flint\Container\Exception\ResolutionException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class AliasContainerTest extends TestCase
{
    public function testAliasChainPreservesSingletonIdentity(): void
    {
        $container = new Container();
        $calls = 0;

        $container->singleton(
            'target',
            static function (ContainerInterface $container) use (&$calls): object {
                ++$calls;

                return new stdClass();
            },
        );

        $container->alias('public', 'intermediate');
        $container->alias('intermediate', 'target');

        self::assertTrue($container->has('public'));
        self::assertSame(0, $calls);

        $resolved = $container->get('public');

        self::assertSame($resolved, $container->get('target'));
        self::assertSame($resolved, $container->get('intermediate'));
        self::assertSame($resolved, $container->get('public'));
        self::assertSame(1, $calls);
    }

    public function testAliasDoesNotCacheATransientTarget(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind(
            'target',
            static function (ContainerInterface $container) use (&$calls): object {
                ++$calls;

                return new stdClass();
            },
        );

        $container->alias('public', 'target');

        $first = $container->get('public');
        $second = $container->get('public');

        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function testAliasCanResolveARegisteredNull(): void
    {
        $container = new Container();

        $container->instance('target', null);
        $container->alias('public', 'target');

        self::assertTrue($container->has('public'));
        self::assertNull($container->get('public'));
    }

    public function testMissingTargetCanBeRegisteredAfterFailedResolution(): void
    {
        $container = new Container();

        $container->alias('public', 'target');

        self::assertTrue($container->has('public'));
        self::assertFalse($container->has('target'));

        try {
            $container->get('public');

            self::fail('An unresolved alias target should fail.');
        } catch (ResolutionException $exception) {
            self::assertInstanceOf(
                EntryNotFoundException::class,
                $exception->getPrevious(),
            );
        }

        $target = new stdClass();

        $container->instance('target', $target);

        self::assertSame($target, $container->get('public'));
    }

    public function testAliasCycleReportsItsPathAndCleansUp(): void
    {
        $container = new Container();

        $container->alias('first', 'second');
        $container->alias('second', 'first');

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $container->get('first');

                self::fail('An alias cycle should fail.');
            } catch (ResolutionException $exception) {
                self::assertSame(
                    'Circular dependency detected: first -> second -> first',
                    $exception->getMessage(),
                );
            }
        }

        $container->instance('healthy', 'ready');

        self::assertSame('ready', $container->get('healthy'));
    }

    public function testSelfAliasIsDetectedDuringResolution(): void
    {
        $container = new Container();

        $container->alias('self', 'self');

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected: self -> self',
        );

        $container->get('self');
    }

    public function testCycleAcrossFactoryAndAliasIsDetected(): void
    {
        $container = new Container();

        $container->singleton(
            'service',
            static fn (ContainerInterface $container): mixed =>
                $container->get('public'),
        );

        $container->alias('public', 'service');

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected: service -> public -> service',
        );

        $container->get('service');
    }

    public function testAliasCannotReplaceAnExistingEntry(): void
    {
        $container = new Container();

        $container->instance('existing', 'original');

        try {
            $container->alias('existing', 'target');

            self::fail('Duplicate registration should fail.');
        } catch (ContainerException $exception) {
            self::assertSame(
                'Container entry "existing" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame('original', $container->get('existing'));
    }

    public function testEmptyTargetDoesNotRegisterTheAlias(): void
    {
        $container = new Container();

        try {
            $container->alias('public', '');

            self::fail('An empty alias target should fail.');
        } catch (ContainerException $exception) {
            self::assertSame(
                'Container alias targets must not be empty.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($container->has('public'));
    }
}
