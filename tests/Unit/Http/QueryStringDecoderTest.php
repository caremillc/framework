<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\QueryStringDecoder;
use Flint\Http\QueryStringEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryStringDecoderTest extends TestCase
{
    public function testAbsentAndEmptyQueriesProduceNoParameters(): void
    {
        self::assertSame([], QueryStringDecoder::decode(null));
        self::assertSame([], QueryStringDecoder::decode(''));
    }

    public function testFieldsSplitAtTheFirstEqualsSign(): void
    {
        self::assertSame(
            [
                'name' => 'Ada',
                'empty' => '',
                'flag' => '',
                'expression' => 'a=b',
            ],
            QueryStringDecoder::decode(
                'name=Ada&empty=&flag&expression=a=b',
            ),
        );
    }

    public function testValuesAreDecodedExactlyOnce(): void
    {
        self::assertSame(
            [
                'space' => 'a b',
                'plus' => 'a+b',
                'slash' => 'a/b',
                'encoded' => '%2F',
            ],
            QueryStringDecoder::decode(
                'space=a+b&plus=a%2Bb&slash=a%2Fb&encoded=%252F',
            ),
        );
    }

    public function testNamesAreNotNormalizedOrExpanded(): void
    {
        self::assertSame(
            [
                'user.name' => 'Ada',
                'display name' => 'Ada Lovelace',
                'filter[active]' => '1',
            ],
            QueryStringDecoder::decode(
                'user.name=Ada'
                . '&display%20name=Ada%20Lovelace'
                . '&filter%5Bactive%5D=1',
            ),
        );
    }

    #[DataProvider('duplicateQueries')]
    public function testDuplicateDecodedNamesAreRejected(string $query): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryStringDecoder::decode($query);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateQueries(): iterable
    {
        yield 'literal duplicate' => ['name=Ada&name=Grace'];
        yield 'encoded duplicate' => ['name=Ada&%6Eame=Grace'];
        yield 'space aliases' => ['display+name=Ada&display%20name=Grace'];
    }

    #[DataProvider('malformedQueries')]
    public function testMalformedEscapesAreRejected(string $query): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryStringDecoder::decode($query);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedQueries(): iterable
    {
        yield 'incomplete escape' => ['name=%'];
        yield 'short escape' => ['name=%2'];
        yield 'non-hexadecimal escape' => ['%GG=value'];
    }

    public function testEmptyNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryStringDecoder::decode('=value');
    }

    public function testParameterLimitAllowsTheBoundaryAndRejectsOverflow(): void
    {
        self::assertSame(
            ['a' => '1', 'b' => '2'],
            QueryStringDecoder::decode('a=1&b=2', maxParameters: 2),
        );

        $this->expectException(InvalidArgumentException::class);

        QueryStringDecoder::decode('a=1&b=2&c=3', maxParameters: 2);
    }

    public function testByteLimitAllowsTheBoundaryAndRejectsOverflow(): void
    {
        self::assertSame(
            ['a' => '1'],
            QueryStringDecoder::decode('a=1', maxBytes: 3),
        );

        $this->expectException(InvalidArgumentException::class);

        QueryStringDecoder::decode('a=12', maxBytes: 3);
    }

    #[DataProvider('invalidLimits')]
    public function testNegativeLimitsAreRejected(
        int $maxParameters,
        int $maxBytes,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        QueryStringDecoder::decode('', $maxParameters, $maxBytes);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'parameter limit' => [-1, 8192];
        yield 'byte limit' => [100, -1];
    }

    public function testGeneratedQueryCanBeDecoded(): void
    {
        $query = QueryStringEncoder::encode([
            'name' => 'Ada Lovelace',
            'expression' => 'a+b/c',
            'enabled' => true,
            'page' => 2,
            'unused' => null,
        ]);

        self::assertSame(
            [
                'name' => 'Ada Lovelace',
                'expression' => 'a+b/c',
                'enabled' => '1',
                'page' => '2',
            ],
            QueryStringDecoder::decode($query),
        );
    }
}
