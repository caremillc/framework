<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\ResolutionException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class AliasContainerTest extends TestCase
{
    public function testAliasReturnsTheRegisteredObject(): void
    {
        $container = new Container();
        $value = new stdClass();

        $container->register('target', $value);
        $container->alias('public', 'target');

        self::assertTrue($container->has('public'));
        self::assertSame($value, $container->get('public'));
        self::assertSame($value, $container->get('target'));
    }

    public function testAliasCanTargetRegisteredNull(): void
    {
        $container = new Container();

        $container->register('target', null);
        $container->alias('public', 'target');

        self::assertTrue($container->has('public'));
        self::assertNull($container->get('public'));
    }

    public function testAliasCanTargetAnotherAlias(): void
    {
        $container = new Container();
        $value = new stdClass();

        $container->register('target', $value);
        $container->alias('first', 'target');
        $container->alias('second', 'first');
        $container->alias('third', 'second');

        self::assertSame($value, $container->get('third'));
        self::assertSame($value, $container->get('second'));
        self::assertSame($value, $container->get('first'));
    }

    public function testAliasesShareOneSingletonCache(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'target',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $container->alias('first', 'target');
        $container->alias('second', 'first');

        self::assertTrue($container->has('second'));
        self::assertCount(0, $calls);

        $value = $container->get('second');

        self::assertSame($value, $container->get('first'));
        self::assertSame($value, $container->get('target'));
        self::assertCount(1, $calls);
    }

    public function testAliasPreservesTransientFactoryBehavior(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->factory(
            'target',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $container->alias('public', 'target');

        $first = $container->get('public');
        $second = $container->get('target');
        $third = $container->get('public');

        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
        self::assertCount(3, $calls);
    }

    public function testUnknownTargetDoesNotReserveTheAliasName(): void
    {
        $container = new Container();

        try {
            $container->alias('public', 'missing');
        } catch (EntryNotFoundException $exception) {
            self::assertSame(
                'No entry is registered for the alias target.',
                $exception->getMessage(),
            );

            self::assertFalse($container->has('public'));

            $container->register('missing', 'available');
            $container->alias('public', 'missing');

            self::assertSame('available', $container->get('public'));

            return;
        }

        self::fail('An alias must reject an unknown target.');
    }

    public function testAliasCannotReplaceAnExistingEntry(): void
    {
        $container = new Container();

        $container->register('target', 'target value');
        $container->register('public', 'original');

        try {
            $container->alias('public', 'target');
        } catch (DuplicateEntryException) {
            self::assertSame('original', $container->get('public'));

            return;
        }

        self::fail('An alias must not replace an existing entry.');
    }

    public function testRegistrationCannotReplaceAnAlias(): void
    {
        $container = new Container();

        $container->register('target', 'original');
        $container->alias('public', 'target');

        try {
            $container->register('public', 'replacement');
        } catch (DuplicateEntryException) {
            self::assertSame('original', $container->get('public'));

            return;
        }

        self::fail('Registration must not replace an alias.');
    }

    public function testSelfAliasWithoutAnExistingEntryIsRejected(): void
    {
        $container = new Container();

        $this->expectException(EntryNotFoundException::class);

        $container->alias('self', 'self');
    }

    public function testEmptyAliasIdentifierIsRejected(): void
    {
        $container = new Container();
        $container->register('target', 'value');

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->alias('', 'target');
    }

    public function testEmptyTargetIdentifierIsRejected(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->alias('public', '');
    }

    public function testNumericLookingAliasIdentifiersRemainDistinct(): void
    {
        $container = new Container();

        $container->register('target', 'value');
        $container->alias('0', 'target');
        $container->alias('00', '0');
        $container->alias(' 0 ', '00');

        self::assertTrue($container->has('0'));
        self::assertTrue($container->has('00'));
        self::assertTrue($container->has(' 0 '));
        self::assertSame('value', $container->get(' 0 '));
    }

    public function testRecursionThroughAnAliasUsesCanonicalResolutionState(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'target',
            static function (ContainerInterface $resolver) use ($calls): mixed {
                $calls->append(true);

                if ($calls->count() === 1) {
                    return $resolver->get('public');
                }

                return 'recovered';
            },
        );

        $container->alias('public', 'target');

        try {
            $container->get('public');
        } catch (ResolutionException $exception) {
            self::assertInstanceOf(
                ResolutionException::class,
                $exception->getPrevious(),
            );

            self::assertSame('recovered', $container->get('target'));
            self::assertSame('recovered', $container->get('public'));
            self::assertCount(2, $calls);

            return;
        }

        self::fail('Resolving a factory through its alias must detect recursion.');
    }
}
