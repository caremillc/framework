<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\ProblemResponseFactory;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProblemResponseExtensionsTest extends TestCase
{
    public function testStructuredExtensionsPreserveStandardMembers(): void
    {
        $extensions = [
            'context' => [
                'operation' => 'greeting',
                'attempts' => [1, 2],
            ],
        ];

        $response = ProblemResponseFactory::create(
            422,
            'Unprocessable Content',
            'The submitted fields are invalid.',
            $extensions,
        );

        self::assertSame(422, $response->status());

        self::assertSame(
            ['application/problem+json'],
            $response->headers()->get('Content-Type'),
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'detail' => 'The submitted fields are invalid.',
                'context' => [
                    'operation' => 'greeting',
                    'attempts' => [1, 2],
                ],
            ],
            json_decode(
                $response->body(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testFalseZeroAndNullExtensionsArePreserved(): void
    {
        $response = ProblemResponseFactory::create(
            400,
            'Bad Request',
            extensions: [
                'retryable' => false,
                'attempts' => 0,
                'context' => null,
            ],
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Bad Request',
                'status' => 400,
                'retryable' => false,
                'attempts' => 0,
                'context' => null,
            ],
            json_decode(
                $response->body(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    #[DataProvider('reservedNames')]
    public function testExtensionsCannotSupplyStandardMembers(
        string $name,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        ProblemResponseFactory::create(
            400,
            'Bad Request',
            extensions: [$name => 'replacement'],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNames(): iterable
    {
        yield 'type' => ['type'];
        yield 'title' => ['title'];
        yield 'status' => ['status'];
        yield 'detail' => ['detail'];
        yield 'instance' => ['instance'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidExtensionNamesAreRejected(
        int|string $name,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        ProblemResponseFactory::create(
            400,
            'Bad Request',
            extensions: [$name => 'value'],
        );
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty string' => [''];
        yield 'integer key' => [0];
    }

    public function testExtensionEncodingFailurePropagates(): void
    {
        $this->expectException(JsonException::class);

        ProblemResponseFactory::create(
            400,
            'Bad Request',
            extensions: [
                'context' => ['invalid_utf8' => "\xFF"],
            ],
        );
    }
}
