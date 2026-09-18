<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\InvalidTagException;
use Careminate\Container\Exception\ResolutionException;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class TaggedContainerTest extends TestCase
{
    public function testTagsPreserveOrderAndIgnoreRepeatedIdentifiers(): void
    {
        $container = new Container();

        $container->register('first', 'one');
        $container->register('second', 'two');
        $container->register('third', 'three');

        $container->tag('group', 'second', 'first', 'second');
        $container->tag('group', 'first', 'third');

        self::assertSame(
            ['two', 'one', 'three'],
            $container->tagged('group'),
        );
    }

    public function testTagsHaveASeparateNamespaceAndPreserveValues(): void
    {
        $container = new Container();

        $container->register('group', 'service value');
        $container->register('null', null);
        $container->register('false', false);
        $container->register('zero', 0);

        $container->tag('group', 'null', 'false', 'zero');

        self::assertSame('service value', $container->get('group'));
        self::assertSame([null, false, 0], $container->tagged('group'));
    }

    public function testUnknownAndEmptyGroupsReturnAnEmptyList(): void
    {
        $container = new Container();

        self::assertSame([], $container->tagged('unknown'));

        $container->tag('empty');

        self::assertSame([], $container->tagged('empty'));
        self::assertFalse($container->has('empty'));
    }

    public function testTagNamesAndIdentifiersAreNotNumericallyCollapsed(): void
    {
        $container = new Container();

        $container->register('0', 'zero');
        $container->register('00', 'double zero');

        $container->tag('0', '0');
        $container->tag('00', '00');

        self::assertSame(['zero'], $container->tagged('0'));
        self::assertSame(['double zero'], $container->tagged('00'));
    }

    public function testRegistrationIsLazyAndTransientFactoriesRunPerRead(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->factory(
            'service',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $container->tag('group', 'service', 'service');

        self::assertCount(0, $calls);

        $first = $container->tagged('group');
        $second = $container->tagged('group');

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0], $second[0]);
        self::assertCount(2, $calls);
    }

    public function testAliasesRemainDistinctAndShareSingletonIdentity(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'service',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $container->alias('alias', 'service');
        $container->tag('group', 'service', 'alias');

        $values = $container->tagged('group');

        self::assertCount(2, $values);
        self::assertSame($values[0], $values[1]);
        self::assertSame($values, $container->tagged('group'));
        self::assertCount(1, $calls);
    }

    public function testInvalidMemberDoesNotPartiallyChangeAnExistingTag(): void
    {
        $container = new Container();

        $container->register('first', 'one');
        $container->register('second', 'two');
        $container->tag('group', 'first');

        try {
            $container->tag('group', 'second', 'missing');
        } catch (EntryNotFoundException) {
            self::assertSame(['one'], $container->tagged('group'));

            return;
        }

        self::fail('A missing tag member must reject registration.');
    }

    public function testInvalidMemberDoesNotPartiallyCreateANewTag(): void
    {
        $container = new Container();

        $container->register('first', 'one');

        try {
            $container->tag('group', 'first', 'missing');
        } catch (EntryNotFoundException) {
            self::assertSame([], $container->tagged('group'));

            return;
        }

        self::fail('A missing tag member must reject registration.');
    }

    public function testEmptyMemberIsRejectedWithoutPartialRegistration(): void
    {
        $container = new Container();

        $container->register('first', 'one');

        try {
            $container->tag('group', 'first', '');
        } catch (InvalidEntryIdentifierException) {
            self::assertSame([], $container->tagged('group'));

            return;
        }

        self::fail('An empty tag member must reject registration.');
    }

    public function testEmptyTagNameIsRejectedOnRegistration(): void
    {
        $container = new Container();

        $this->expectException(InvalidTagException::class);

        $container->tag('');
    }

    public function testEmptyTagNameIsRejectedOnResolution(): void
    {
        $container = new Container();

        $this->expectException(InvalidTagException::class);

        $container->tagged('');
    }

    public function testCurrentReadUsesAMembershipSnapshot(): void
    {
        $container = new Container();

        $container->register('later', 'later value');

        $container->factory(
            'first',
            static function (ContainerInterface $resolver) use ($container): string {
                $container->tag('group', 'later');

                return 'first value';
            },
        );

        $container->tag('group', 'first');

        self::assertSame(
            ['first value'],
            $container->tagged('group'),
        );

        self::assertSame(
            ['first value', 'later value'],
            $container->tagged('group'),
        );
    }

    public function testResolutionFailurePreservesCauseAndAliasPath(): void
    {
        $container = new Container();
        $original = new Error('Factory failed.');

        $container->factory(
            'broken',
            static function (ContainerInterface $resolver) use ($original): never {
                throw $original;
            },
        );

        $container->alias('broken.alias', 'broken');
        $container->tag('group', 'broken.alias');

        try {
            $container->tagged('group');
        } catch (ResolutionException $failure) {
            self::assertSame($original, $failure->getPrevious());

            self::assertSame(
                ['broken.alias'],
                $failure->dependencyPath(),
            );

            $container->register('healthy', 'healthy value');
            $container->tag('healthy.group', 'healthy');

            self::assertSame(
                ['healthy value'],
                $container->tagged('healthy.group'),
            );

            return;
        }

        self::fail('A failed tagged service must propagate its resolution failure.');
    }
}
