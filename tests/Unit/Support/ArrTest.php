<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Support;

use Careminate\Exception\InvalidArgumentException;
use Careminate\Support\Arr;
use PHPUnit\Framework\TestCase;

final class ArrTest extends TestCase
{
    public function testGetReturnsNestedValue(): void
    {
        $values = [
            'database' => [
                'connections' => [
                    'default' => 'mysql',
                ],
            ],
        ];

        self::assertSame(
            'mysql',
            Arr::get($values, 'database.connections.default'),
        );
    }

    public function testGetPrefersAnExactDottedKey(): void
    {
        $values = [
            'database.host' => 'literal-host',
            'database' => [
                'host' => 'nested-host',
            ],
        ];

        self::assertSame(
            'literal-host',
            Arr::get($values, 'database.host'),
        );
    }

    public function testGetReturnsDefaultForMissingPath(): void
    {
        self::assertSame(
            'fallback',
            Arr::get([], 'database.host', 'fallback'),
        );
    }

    public function testGetReturnsEntireArrayForNullKey(): void
    {
        $values = ['enabled' => true];

        self::assertSame($values, Arr::get($values, null));
    }

    public function testHasRecognizesExistingNullValue(): void
    {
        $values = [
            'database' => [
                'password' => null,
            ],
        ];

        self::assertTrue(Arr::has($values, 'database.password'));
        self::assertFalse(Arr::has($values, 'database.username'));
    }

    public function testSetCreatesNestedArrayWithoutMutatingSource(): void
    {
        $source = [
            'application' => [
                'name' => 'Caremi',
            ],
        ];

        $result = Arr::set(
            $source,
            'database.connections.default',
            'sqlite',
        );

        self::assertSame(
            'sqlite',
            Arr::get($result, 'database.connections.default'),
        );

        self::assertFalse(Arr::has($source, 'database'));
    }

    public function testSetReplacesScalarWithNestedStructure(): void
    {
        $result = Arr::set(
            ['database' => 'invalid'],
            'database.host',
            'localhost',
        );

        self::assertSame(
            ['database' => ['host' => 'localhost']],
            $result,
        );
    }

    public function testSetRejectsEmptyPathSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains an empty segment');

        Arr::set([], 'database..host', 'localhost');
    }

    public function testOnlyReturnsRequestedExistingKeys(): void
    {
        $values = [
            'name' => 'Caremi',
            'debug' => false,
            'timezone' => 'Asia/Dubai',
        ];

        self::assertSame(
            [
                'name' => 'Caremi',
                'timezone' => 'Asia/Dubai',
            ],
            Arr::only($values, ['name', 'timezone', 'missing']),
        );
    }

    public function testExceptRemovesRequestedKeys(): void
    {
        $values = [
            'name' => 'Caremi',
            'debug' => false,
            'timezone' => 'Asia/Dubai',
        ];

        self::assertSame(
            [
                'name' => 'Caremi',
                'timezone' => 'Asia/Dubai',
            ],
            Arr::except($values, ['debug']),
        );
    }
}
