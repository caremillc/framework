<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\ResponseFactory;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseFactoryTest extends TestCase
{
    public function testTextResponsePreservesBodyAndSetsContentType(): void
    {
        $body = "Hello\nFlint";
        $response = ResponseFactory::text($body);

        self::assertSame(200, $response->status());
        self::assertSame($body, $response->body());
        self::assertSame(
            ['text/plain; charset=utf-8'],
            $response->headers()->get('Content-Type'),
        );
    }

    public function testTextResponseSupportsCustomStatus(): void
    {
        $response = ResponseFactory::text('Created', status: 201);

        self::assertSame(201, $response->status());
        self::assertSame('Created', $response->body());
    }

    public function testJsonResponseEncodesObjectAndSetsContentType(): void
    {
        $response = ResponseFactory::json(
            ['name' => 'Flint', 'ready' => true],
            status: 201,
        );

        self::assertSame(201, $response->status());
        self::assertSame(
            '{"name":"Flint","ready":true}',
            $response->body(),
        );
        self::assertSame(
            ['application/json'],
            $response->headers()->get('Content-Type'),
        );
    }

    public function testJsonResponsePreservesListShape(): void
    {
        $response = ResponseFactory::json(['first', 'second']);

        self::assertSame(
            '["first","second"]',
            $response->body(),
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function scalarValues(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'false' => [false, 'false'];
        yield 'zero' => [0, '0'];
        yield 'numeric string' => ['123', '"123"'];
    }

    #[DataProvider('scalarValues')]
    public function testJsonResponsePreservesScalarType(
        mixed $value,
        string $expected,
    ): void {
        $response = ResponseFactory::json($value);

        self::assertSame($expected, $response->body());
    }

    public function testJsonResponseLeavesUnicodeAndSlashesUnescaped(): void
    {
        $response = ResponseFactory::json([
            'name' => 'Café',
            'path' => '/health',
        ]);

        self::assertSame(
            '{"name":"Café","path":"/health"}',
            $response->body(),
        );
    }

    public function testInvalidUtf8ThrowsInsteadOfProducingPartialJson(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_UTF8);

        ResponseFactory::json([
            'valid' => 'value',
            'invalid' => "\xB1",
        ]);
    }

    public function testNonFiniteNumberIsRejected(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_INF_OR_NAN);

        ResponseFactory::json(['value' => INF]);
    }

    public function testJsonCannotBeUsedWithNoContentStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Response status 204 requires an empty body.',
        );

        ResponseFactory::json(null, status: 204);
    }
}
