<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\QueryParameters;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryParametersTest extends TestCase
{
    public function testMissingValuesUseDefaults(): void
    {
        $query = new QueryParameters([]);

        self::assertFalse($query->has('missing'));
        self::assertNull($query->string('missing'));
        self::assertNull($query->integer('missing'));
        self::assertNull($query->boolean('missing'));

        self::assertSame('fallback', $query->string('missing', 'fallback'));
        self::assertSame(12, $query->integer('missing', 12));
        self::assertFalse($query->boolean('missing', false));
    }

    public function testPresentStringsPreserveTheirBytes(): void
    {
        $query = new QueryParameters([
            'empty' => '',
            'name' => ' Ada ',
            'zero' => '0',
        ]);

        self::assertTrue($query->has('empty'));
        self::assertSame('', $query->string('empty', 'fallback'));
        self::assertSame(' Ada ', $query->string('name'));
        self::assertSame('0', $query->string('zero'));
    }

    public function testConstructionCapturesIndependentValues(): void
    {
        $values = ['name' => 'Ada'];
        $first = new QueryParameters($values);

        $values['name'] = 'Grace';
        $second = new QueryParameters($values);

        self::assertSame('Ada', $first->string('name'));
        self::assertSame('Grace', $second->string('name'));
    }

    #[DataProvider('validIntegers')]
    public function testCanonicalIntegersAreAccepted(
        string $value,
        int $expected,
    ): void {
        $query = new QueryParameters(['number' => $value]);

        self::assertSame($expected, $query->integer('number'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validIntegers(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'positive' => ['42', 42];
        yield 'negative' => ['-42', -42];
        yield 'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'minimum' => [(string) PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('invalidIntegers')]
    public function testInvalidIntegersAreRejected(string $value): void
    {
        $query = new QueryParameters(['number' => $value]);

        $this->expectException(InvalidArgumentException::class);

        $query->integer('number');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIntegers(): iterable
    {
        yield 'empty' => [''];
        yield 'leading zero' => ['02'];
        yield 'explicit plus' => ['+2'];
        yield 'negative zero' => ['-0'];
        yield 'leading whitespace' => [' 2'];
        yield 'decimal' => ['2.0'];
        yield 'exponent' => ['2e1'];
        yield 'positive overflow' => [(string) PHP_INT_MAX . '0'];
        yield 'negative overflow' => [(string) PHP_INT_MIN . '0'];
    }

    #[DataProvider('validBooleans')]
    public function testExplicitBooleanValuesAreAccepted(
        string $value,
        bool $expected,
    ): void {
        $query = new QueryParameters(['enabled' => $value]);

        self::assertSame($expected, $query->boolean('enabled'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validBooleans(): iterable
    {
        yield 'true word' => ['true', true];
        yield 'false word' => ['false', false];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
    }

    #[DataProvider('invalidBooleans')]
    public function testOtherBooleanValuesAreRejected(string $value): void
    {
        $query = new QueryParameters(['enabled' => $value]);

        $this->expectException(InvalidArgumentException::class);

        $query->boolean('enabled');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBooleans(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['TRUE'];
        yield 'yes' => ['yes'];
        yield 'whitespace' => [' true'];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    #[DataProvider('invalidMaps')]
    public function testInvalidMapsAreRejected(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);

        new QueryParameters($values);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidMaps(): iterable
    {
        yield 'empty name' => [['' => 'value']];
        yield 'integer name' => [[0 => 'value']];
        yield 'null value' => [['name' => null]];
        yield 'integer value' => [['repeat' => 2]];
        yield 'array value' => [['name' => ['Ada']]];
    }
}
