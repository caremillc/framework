<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;

final class ContainerTest extends TestCase
{
    #[DataProvider('values')]
    public function testRegisteredValuesCanBeReadThroughPsrInterface(
        mixed $value,
    ): void {
        $container = new Container();
        $container->register('entry', $value);

        self::assertRegisteredValue($container, 'entry', $value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'zero' => [0];
        yield 'negative integer' => [-17];
        yield 'float' => [1.25];
        yield 'empty string' => [''];
        yield 'string' => ['configured value'];
        yield 'empty array' => [[]];
        yield 'nested array' => [['options' => ['enabled' => true]]];
    }

    public function testRepeatedReadsPreserveObjectIdentity(): void
    {
        $container = new Container();
        $object = new stdClass();

        $container->register('object', $object);

        self::assertSame($object, $container->get('object'));
        self::assertSame($object, $container->get('object'));
    }

    public function testClosuresAreStoredWithoutExecution(): void
    {
        $calls = 0;

        $callback = static function () use (&$calls): void {
            ++$calls;
        };

        $container = new Container();
        $container->register('callback', $callback);

        self::assertTrue($container->has('callback'));
        self::assertSame($callback, $container->get('callback'));
        self::assertSame(0, $calls);
    }

    public function testResourcesCanBeRegisteredAsValues(): void
    {
        $resource = fopen('php://memory', 'r+');

        self::assertIsResource($resource);

        try {
            $container = new Container();
            $container->register('stream', $resource);

            self::assertSame($resource, $container->get('stream'));
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('missingIdentifiers')]
    public function testUnknownIdentifiersFollowThePsrNotFoundContract(
        string $id,
    ): void {
        $container = new Container();

        self::assertFalse($container->has($id));

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage(
            'No entry is registered for the requested identifier.',
        );

        $container->get($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingIdentifiers(): iterable
    {
        yield 'ordinary identifier' => ['missing'];
        yield 'empty identifier' => [''];
        yield 'identifier containing control characters' => ["missing\nvalue"];
    }

    public function testEmptyIdentifiersCannotBeRegistered(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);
        $this->expectExceptionMessage(
            'Container entry identifiers must not be empty.',
        );

        $container->register('', 'value');
    }

    #[DataProvider('values')]
    public function testDuplicateRegistrationPreservesTheOriginalValue(
        mixed $original,
    ): void {
        $container = new Container();
        $container->register('entry', $original);

        try {
            $container->register('entry', 'replacement');
        } catch (DuplicateEntryException $exception) {
            self::assertSame(
                'An entry is already registered for the requested identifier.',
                $exception->getMessage(),
            );

            self::assertTrue($container->has('entry'));
            self::assertSame($original, $container->get('entry'));

            return;
        }

        self::fail('Duplicate registration must throw an exception.');
    }

    public function testIdentifiersAreNotNormalizedOrCoerced(): void
    {
        $container = new Container();

        $identifiers = [
            '0',
            '00',
            '+0',
            '-0',
            ' 0 ',
            'Service',
            'service',
            ' ',
            "entry\0suffix",
        ];

        foreach ($identifiers as $index => $id) {
            $container->register($id, $index);
        }

        foreach ($identifiers as $index => $id) {
            self::assertTrue($container->has($id));
            self::assertSame($index, $container->get($id));
        }
    }

    public function testContainersDoNotShareRegistrationState(): void
    {
        $first = new Container();
        $second = new Container();

        $first->register('entry', 'first');
        $second->register('entry', 'second');

        self::assertSame('first', $first->get('entry'));
        self::assertSame('second', $second->get('entry'));
    }

    public function testClassNamesAreNotAutomaticallyRegistered(): void
    {
        $container = new Container();

        self::assertFalse($container->has(stdClass::class));

        $this->expectException(NotFoundExceptionInterface::class);

        $container->get(stdClass::class);
    }

    private static function assertRegisteredValue(
        ContainerInterface $container,
        string $id,
        mixed $expected,
    ): void {
        self::assertTrue($container->has($id));
        self::assertSame($expected, $container->get($id));
    }
}
