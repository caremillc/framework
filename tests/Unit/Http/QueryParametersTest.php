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
        self::assertSame(10, $query->integer('missing', 10));
        self::assertFalse($query->boolean('missing', false));
    }

    public function testPresentEmptyStringDoesNotUseDefault(): void
    {
        $query = new QueryParameters(['name' => '']);

        self::assertTrue($query->has('name'));
        self::assertSame('', $query->string('name', 'fallback'));
    }

    public function testSourceChangesDoNotChangeStoredValues(): void
    {
        $values = ['name' => 'Ada'];
        $query = new QueryParameters($values);

        $values['name'] = 'Grace';

        self::assertSame('Ada', $query->string('name'));
    }

    #[DataProvider('validIntegers')]
    public function testCanonicalIntegersAreAccepted(
        string $value,
        int $expected,
    ): void {
        $query = new QueryParameters(['count' => $value]);

        self::assertSame($expected, $query->integer('count'));
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
    public function testInvalidIntegersDoNotFallBackToDefault(
        string $value,
    ): void {
        $query = new QueryParameters(['count' => $value]);

        $this->expectException(InvalidArgumentException::class);

        $query->integer('count', 10);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIntegers(): iterable
    {
        yield 'empty' => [''];
        yield 'leading zero' => ['01'];
        yield 'explicit plus' => ['+1'];
        yield 'negative zero' => ['-0'];
        yield 'leading whitespace' => [' 1'];
        yield 'trailing whitespace' => ['1 '];
        yield 'decimal' => ['1.5'];
        yield 'exponent' => ['1e3'];
        yield 'overflow' => [(string) PHP_INT_MAX . '0'];
    }

    #[DataProvider('validBooleans')]
    public function testSupportedBooleansAreAccepted(
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
    public function testInvalidBooleansDoNotFallBackToDefault(
        string $value,
    ): void {
        $query = new QueryParameters(['enabled' => $value]);

        $this->expectException(InvalidArgumentException::class);

        $query->boolean('enabled', false);
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
        yield 'numeric name' => [[0 => 'value']];
        yield 'integer value' => [['count' => 1]];
        yield 'null value' => [['name' => null]];
        yield 'nested value' => [['filter' => ['name' => 'Ada']]];
    }
}
