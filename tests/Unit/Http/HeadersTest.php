<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $headers = new Headers();

        self::assertFalse($headers->has('Content-Type'));
        self::assertSame([], $headers->get('Content-Type'));
        self::assertSame([], $headers->all());
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $headers = new Headers()->with('Content-Type', 'text/plain');

        self::assertTrue($headers->has('CONTENT-TYPE'));
        self::assertSame(
            ['text/plain'],
            $headers->get('content-type'),
        );
    }

    public function testReplacementPreservesOriginal(): void
    {
        $original = new Headers()->with('X-Mode', 'first', 'second');
        $updated = $original->with('x-mode', 'replacement');

        self::assertNotSame($original, $updated);
        self::assertSame(['first', 'second'], $original->get('X-Mode'));
        self::assertSame(['replacement'], $updated->get('X-Mode'));
    }

    public function testRepeatedCookiesRemainSeparateAndOrdered(): void
    {
        $original = new Headers()->with('Set-Cookie', 'first=1');
        $updated = $original->withAdded('set-cookie', 'second=2');

        self::assertSame(['first=1'], $original->get('Set-Cookie'));
        self::assertSame(
            ['first=1', 'second=2'],
            $updated->get('SET-COOKIE'),
        );
    }

    public function testRemovalPreservesOtherHeadersAndOriginal(): void
    {
        $original = new Headers()
            ->with('X-First', 'one')
            ->with('X-Second', 'two');

        $updated = $original->without('x-first');

        self::assertTrue($original->has('X-First'));
        self::assertFalse($updated->has('X-First'));
        self::assertSame(['two'], $updated->get('X-Second'));
    }

    public function testExportsNormalizedNamesIncludingNumericNames(): void
    {
        $headers = new Headers()
            ->with('X-Example', 'value')
            ->with('123', 'numeric name');

        self::assertSame(
            [
                ['name' => 'x-example', 'values' => ['value']],
                ['name' => '123', 'values' => ['numeric name']],
            ],
            $headers->all(),
        );
    }

    public function testTrimsSurroundingWhitespaceAndAllowsEmptyValues(): void
    {
        $headers = new Headers()->with(
            'X-Example',
            " \tvalue\t ",
            '',
        );

        self::assertSame(
            ['value', ''],
            $headers->get('X-Example'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['Bad Name'];
        yield 'colon' => ['Bad:Name'];
        yield 'newline' => ["Bad\nName"];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Headers()->with($name, 'value');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'carriage return' => ["value\rInjected: true"];
        yield 'line feed' => ["value\nInjected: true"];
        yield 'null byte' => ["value\0"];
        yield 'delete character' => ["value\x7F"];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Headers()->with('X-Example', $value);
    }

    public function testRequiresAtLeastOneValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A header requires at least one value.',
        );

        new Headers()->with('X-Example');
    }
}
