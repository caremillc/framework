<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Exception\InvalidJsonException;
use Flint\Http\JsonObjectDecoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonObjectDecoderTest extends TestCase
{
    public function testEmptyObjectIsAccepted(): void
    {
        $decoded = JsonObjectDecoder::decode('{}');

        self::assertSame([], get_object_vars($decoded));
    }

    public function testNestedValuesPreserveTheirTypes(): void
    {
        $decoded = JsonObjectDecoder::decode(
            '{"name":"Caf\u00e9","count":2,"ratio":1.5,'
            . '"enabled":true,"disabled":false,"missing":null,'
            . '"items":["first",2],"nested":{"value":"kept"}}',
        );

        self::assertSame("Caf\xC3\xA9", $decoded->name);
        self::assertSame(2, $decoded->count);
        self::assertSame(1.5, $decoded->ratio);
        self::assertTrue($decoded->enabled);
        self::assertFalse($decoded->disabled);
        self::assertTrue(property_exists($decoded, 'missing'));
        self::assertNull($decoded->missing);
        self::assertSame(['first', 2], $decoded->items);

        self::assertInstanceOf(stdClass::class, $decoded->nested);
        self::assertSame('kept', $decoded->nested->value);
    }

    public function testLargeIntegersArePreservedAsStrings(): void
    {
        $decoded = JsonObjectDecoder::decode(
            '{"id":9223372036854775808}',
        );

        self::assertSame('9223372036854775808', $decoded->id);
    }

    public function testExcessiveNestingIsRejected(): void
    {
        $this->expectException(InvalidJsonException::class);

        JsonObjectDecoder::decode(
            '{"outer":{"inner":{"value":1}}}',
            maxDepth: 2,
        );
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodiesAreRejected(string $body): void
    {
        $this->expectException(InvalidJsonException::class);

        JsonObjectDecoder::decode($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'empty body' => [''];
        yield 'incomplete object' => ['{"name":'];
        yield 'trailing comma' => ['{"name":"Ada",}'];
        yield 'invalid UTF-8' => ["{\"name\":\"\xFF\"}"];
        yield 'multiple documents' => ['{}{}'];
    }

    #[DataProvider('nonObjectBodies')]
    public function testNonObjectRootsAreRejected(string $body): void
    {
        $this->expectException(InvalidJsonException::class);

        JsonObjectDecoder::decode($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectBodies(): iterable
    {
        yield 'empty array' => ['[]'];
        yield 'populated array' => ['[1,2]'];
        yield 'null' => ['null'];
        yield 'boolean' => ['true'];
        yield 'number' => ['42'];
        yield 'string' => ['"Ada"'];
    }

    #[DataProvider('invalidDepths')]
    public function testInvalidDepthSettingsAreRejected(int $depth): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonObjectDecoder::decode('{}', maxDepth: $depth);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDepths(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above supported limit' => [513];
    }
}
