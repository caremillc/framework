<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Container;

use Flint\Container\Container;
use Flint\Container\Exception\ContainerException;
use Flint\Container\Exception\EntryNotFoundException;
use Flint\Container\Exception\ResolutionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testHasDoesNotExecuteAFactory(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind(
            'service',
            static function (ContainerInterface $container) use (&$calls): object {
                ++$calls;

                return new stdClass();
            },
        );

        self::assertTrue($container->has('service'));
        self::assertFalse($container->has('missing'));
        self::assertSame(0, $calls);
    }

    public function testFactoriesRunOnEveryResolution(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind(
            'service',
            static function (ContainerInterface $container) use (&$calls): object {
                ++$calls;

                return new stdClass();
            },
        );

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function testFactoryReceivesTheResolvingContainer(): void
    {
        $container = new Container();
        $dependency = new stdClass();

        $container->instance('dependency', $dependency);

        $container->bind(
            'service',
            static fn (ContainerInterface $container): mixed =>
                $container->get('dependency'),
        );

        self::assertSame($dependency, $container->get('service'));
    }

    public function testRegisteredObjectsPreserveIdentity(): void
    {
        $container = new Container();
        $instance = new stdClass();

        $container->instance('service', $instance);

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertSame($instance, $first);
        self::assertSame($instance, $second);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function instanceValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'array' => [['enabled' => true]];
    }

    #[DataProvider('instanceValues')]
    public function testRegisteredValuesRemainAvailable(mixed $value): void
    {
        $container = new Container();

        $container->instance('value', $value);

        self::assertTrue($container->has('value'));
        self::assertSame($value, $container->get('value'));
    }

    public function testMissingEntryThrowsANotFoundException(): void
    {
        $container = new Container();

        self::assertFalse($container->has('missing'));

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage(
            'Container entry "missing" is not registered.',
        );

        $container->get('missing');
    }

    public function testDuplicateRegistrationPreservesTheOriginalEntry(): void
    {
        $container = new Container();
        $original = new stdClass();

        $container->instance('service', $original);

        try {
            $container->bind(
                'service',
                static fn (ContainerInterface $container): object =>
                    new stdClass(),
            );

            self::fail('Duplicate registration should fail.');
        } catch (ContainerException $exception) {
            self::assertSame(
                'Container entry "service" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame($original, $container->get('service'));
    }

    public function testEmptyRegistrationIsRejected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Container entry identifiers must not be empty.',
        );

        $container->instance('', null);
    }

    public function testMissingNestedDependencyIsAResolutionFailure(): void
    {
        $container = new Container();

        $container->bind(
            'service',
            static fn (ContainerInterface $container): mixed =>
                $container->get('missing'),
        );

        self::assertTrue($container->has('service'));

        try {
            $container->get('service');

            self::fail('The missing dependency should prevent resolution.');
        } catch (ResolutionException $exception) {
            self::assertInstanceOf(
                EntryNotFoundException::class,
                $exception->getPrevious(),
            );
        }
    }

    public function testFactoryFailurePreservesTheCauseAndAllowsRetry(): void
    {
        $container = new Container();
        $failure = new RuntimeException('Sensitive underlying detail.');
        $attempts = 0;

        $container->bind(
            'unstable',
            static function (ContainerInterface $container) use (
                &$attempts,
                $failure,
            ): string {
                ++$attempts;

                if ($attempts === 1) {
                    throw $failure;
                }

                return 'recovered';
            },
        );

        try {
            $container->get('unstable');

            self::fail('The first resolution should fail.');
        } catch (ResolutionException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertStringNotContainsString(
                'Sensitive underlying detail.',
                $exception->getMessage(),
            );
        }

        self::assertSame('recovered', $container->get('unstable'));
        self::assertSame(2, $attempts);
    }

    public function testCircularDependencyReportsItsPathAndCleansUp(): void
    {
        $container = new Container();

        $container->bind(
            'first',
            static fn (ContainerInterface $container): mixed =>
                $container->get('second'),
        );

        $container->bind(
            'second',
            static fn (ContainerInterface $container): mixed =>
                $container->get('first'),
        );

        try {
            $container->get('first');

            self::fail('Circular resolution should fail.');
        } catch (ResolutionException $exception) {
            self::assertSame(
                'Circular dependency detected: first -> second -> first',
                $exception->getMessage(),
            );
        }

        $container->instance('healthy', 'ready');

        self::assertSame('ready', $container->get('healthy'));
    }

    public function testRegistrationDuringResolutionIsRejected(): void
    {
        $container = new Container();

        $container->bind(
            'mutating',
            static function (ContainerInterface $resolver) use ($container): null {
                $container->instance('unexpected', true);

                return null;
            },
        );

        try {
            $container->get('mutating');

            self::fail('Registration during resolution should fail.');
        } catch (ResolutionException $exception) {
            self::assertInstanceOf(
                ContainerException::class,
                $exception->getPrevious(),
            );
        }

        self::assertFalse($container->has('unexpected'));

        $container->instance('after-failure', true);

        self::assertTrue($container->get('after-failure'));
    }

    public function testContainersDoNotShareRegistrations(): void
    {
        $first = new Container();
        $second = new Container();

        $first->instance('private', 'first container');

        self::assertTrue($first->has('private'));
        self::assertFalse($second->has('private'));
    }

    public function testIdentifiersAreCaseSensitive(): void
    {
        $container = new Container();

        $container->instance('Service', 'registered');

        self::assertTrue($container->has('Service'));
        self::assertFalse($container->has('service'));
    }
}
