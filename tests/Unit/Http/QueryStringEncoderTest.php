<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\QueryStringEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class QueryStringEncoderTest extends TestCase
{
    public function testEmptyQueryProducesAnEmptyString(): void
    {
        self::assertSame('', QueryStringEncoder::encode([]));
        self::assertSame('', QueryStringEncoder::encode(['missing' => null]));
    }

    public function testReservedCharactersAreEncoded(): void
    {
        self::assertSame(
            'q=a%20b%2Bc%2Fd%3Fe%26f%3Dg%23h%25',
            QueryStringEncoder::encode([
                'q' => 'a b+c/d?e&f=g#h%',
            ]),
        );
    }

    public function testScalarValuesHaveExplicitRepresentations(): void
    {
        self::assertSame(
            'empty=&zero=0&enabled=1&disabled=0',
            QueryStringEncoder::encode([
                'empty' => '',
                'zero' => 0,
                'enabled' => true,
                'disabled' => false,
                'missing' => null,
            ]),
        );
    }

    public function testNamesAndUtf8ValuesAreEncoded(): void
    {
        self::assertSame(
            'display%20name=Caf%C3%A9',
            QueryStringEncoder::encode([
                'display name' => "Caf\xC3\xA9",
            ]),
        );
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRejected(array $parameters): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryStringEncoder::encode($parameters);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'empty name' => [['' => 'value']];
        yield 'integer key' => [[0 => 'value']];
        yield 'nested array' => [['filter' => ['active']]];
        yield 'object' => [['filter' => new stdClass()]];
        yield 'float' => [['amount' => 1.5]];
    }
}
