<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\Exception\PortableValueException;
use Careminate\Container\Compilation\Internal\PortableValueCodec;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PortableValueCodecTest extends TestCase
{
    #[DataProvider('portableValues')]
    public function testSupportedValuesRoundTripExactly(mixed $value): void
    {
        $codec = new PortableValueCodec();

        $encoded = $codec->encode($value);
        $decoded = $codec->decode($encoded);

        self::assertSame($value, $decoded);
        self::assertSame($encoded, $codec->encode($decoded));

        if (is_float($value)) {
            self::assertIsFloat($decoded);
            self::assertSame(pack('E', $value), pack('E', $decoded));
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function portableValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'integer zero' => [0];
        yield 'minimum integer' => [PHP_INT_MIN];
        yield 'maximum integer' => [PHP_INT_MAX];
        yield 'float zero' => [0.0];
        yield 'negative float zero' => [-0.0];
        yield 'fraction' => [0.12345678901234568];
        yield 'maximum float' => [PHP_FLOAT_MAX];
        yield 'small float' => [PHP_FLOAT_MIN];
        yield 'empty string' => [''];
        yield 'numeric string' => ['123'];
        yield 'binary string' => ["\0\xff\xfe"];
        yield 'empty array' => [[]];
        yield 'list' => [[null, true, 1, 1.0, '1']];
        yield 'ordered mixed keys' => [[
            '08' => 'leading zero',
            8 => null,
            -2 => false,
            '' => [],
            "\0\xff" => ['nested' => 1.25],
        ]];
    }

    #[DataProvider('unsupportedValues')]
    public function testUnsupportedValuesAreRejected(mixed $value): void
    {
        $this->expectException(PortableValueException::class);

        new PortableValueCodec()->encode($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsupportedValues(): iterable
    {
        yield 'object' => [new stdClass()];
        yield 'closure' => [static fn (): int => 1];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
        yield 'nested object' => [['entry' => new stdClass()]];
    }

    #[DataProvider('invalidDocuments')]
    public function testMalformedDocumentsAreRejected(string $document): void
    {
        $this->expectException(PortableValueException::class);

        new PortableValueCodec()->decode($document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'invalid JSON' => ['['];
        yield 'object instead of node' => ['{"0":"null","1":null}'];
        yield 'missing payload' => ['["null"]'];
        yield 'extra field' => ['["null",null,0]'];
        yield 'unknown tag' => ['["object",null]'];
        yield 'invalid null' => ['["null",false]'];
        yield 'invalid boolean' => ['["bool",1]'];
        yield 'integer string' => ['["int","1"]'];
        yield 'integer fraction' => ['["int",1.0]'];
        yield 'integer overflow' => ['["int",9223372036854775808]'];
        yield 'invalid float' => ['["float","xyz"]'];
        yield 'infinite float' => ['["float","7ff0000000000000"]'];
        yield 'NaN float' => ['["float","7ff8000000000000"]'];
        yield 'invalid base64' => ['["string","!"]'];
        yield 'noncanonical base64' => ['["string","YQ"]'];
        yield 'object entry list' => ['["array",{}]'];
        yield 'invalid pair' => ['["array",[[]]]'];
        yield 'boolean key' => ['["array",[[["bool",true],["null",null]]]]'];
        yield 'coerced string key' => ['["array",[[["string","MQ=="],["null",null]]]]'];
        yield 'duplicate null-valued key' => [
            '["array",[[["int",1],["null",null]],[["int",1],["bool",true]]]]',
        ];
    }

    public function testResourceIsRejectedAndClosedByItsOwner(): void
    {
        $resource = fopen('php://memory', 'r+');

        self::assertIsResource($resource);

        try {
            $this->expectException(PortableValueException::class);

            new PortableValueCodec()->encode($resource);
        } finally {
            fclose($resource);
        }
    }

    public function testNestingBoundaryIsSymmetric(): void
    {
        $codec = new PortableValueCodec();
        $value = null;

        for ($depth = 0; $depth < 32; ++$depth) {
            $value = [$value];
        }

        $encoded = $codec->encode($value);

        self::assertSame($value, $codec->decode($encoded));

        self::captureFailure(
            static fn (): string => $codec->encode([$value]),
        );

        self::captureFailure(
            static fn (): mixed => $codec->decode(
                '["array",[[["int",0],' . $encoded . ']]]',
            ),
        );
    }

    public function testRecursiveArrayIsRejected(): void
    {
        $value = [];
        $value['self'] = &$value;

        $this->expectException(PortableValueException::class);
        $this->expectExceptionMessage(
            'The portable value exceeds its nesting limit.',
        );

        new PortableValueCodec()->encode($value);
    }

    public function testNodeLimitAppliesInBothDirections(): void
    {
        $codec = new PortableValueCodec();

        self::captureFailure(
            static fn (): string => $codec->encode(array_fill(0, 5000, null)),
        );

        $entries = [];

        for ($index = 0; $index < 5000; ++$index) {
            $entries[] = '[["int",' . $index . '],["null",null]]';
        }

        $document = '["array",[' . implode(',', $entries) . ']]';

        $failure = self::captureFailure(
            static fn (): mixed => $codec->decode($document),
        );

        self::assertSame(
            'The portable value exceeds its node limit.',
            $failure->getMessage(),
        );
    }

    public function testEncodedByteLimitAppliesInBothDirections(): void
    {
        $codec = new PortableValueCodec();

        // Base64 expansion takes this beyond the encoded document limit.
        self::captureFailure(
            static fn (): string => $codec->encode(str_repeat('a', 800000)),
        );

        $failure = self::captureFailure(
            static fn (): mixed => $codec->decode(str_repeat(' ', 1048577)),
        );

        self::assertSame(
            'The portable value exceeds its encoded byte limit.',
            $failure->getMessage(),
        );
    }

    public function testAggregateStringBudgetIsEnforced(): void
    {
        $this->expectException(PortableValueException::class);
        $this->expectExceptionMessage(
            'The portable value exceeds its string byte limit.',
        );

        new PortableValueCodec()->encode([
            str_repeat('a', 600000),
            str_repeat('b', 600000),
        ]);
    }

    public function testErrorsDoNotExposeValuesAndDoNotPoisonTheCodec(): void
    {
        $codec = new PortableValueCodec();

        $failure = self::captureFailure(
            static fn (): string => $codec->encode([
                'private-secret-marker' => new stdClass(),
            ]),
        );

        self::assertSame(
            'The value contains an unsupported type.',
            $failure->getMessage(),
        );

        self::assertNull($failure->getPrevious());
        self::assertSame(42, $codec->decode($codec->encode(42)));
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): PortableValueException {
        try {
            $operation();
        } catch (PortableValueException $exception) {
            return $exception;
        }

        self::fail('The operation must reject the portable value.');
    }
}
