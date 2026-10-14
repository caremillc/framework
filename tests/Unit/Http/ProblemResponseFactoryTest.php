<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\ProblemResponseFactory;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProblemResponseFactoryTest extends TestCase
{
    public function testProblemStatusAndMediaTypeMatchTheDocument(): void
    {
        $response = ProblemResponseFactory::create(
            422,
            'Unprocessable Content',
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
            ],
            json_decode(
                $response->body(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testClientFacingDetailCanBeIncluded(): void
    {
        $response = ProblemResponseFactory::create(
            400,
            'Bad Request',
            'The request body must contain a JSON object.',
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'The request body must contain a JSON object.',
            ],
            json_decode(
                $response->body(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testDetailIsEncodedWithoutChangingItsValue(): void
    {
        $detail = "Invalid \"name\".\nUse a value such as Caf\xC3\xA9.";

        $response = ProblemResponseFactory::create(
            422,
            'Unprocessable Content',
            $detail,
        );

        self::assertStringNotContainsString("\n", $response->body());
        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'detail' => $detail,
            ],
            json_decode(
                $response->body(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testExplicitEmptyDetailIsPreserved(): void
    {
        $response = ProblemResponseFactory::create(
            400,
            'Bad Request',
            '',
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => '',
            ],
            json_decode(
                $response->body(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testInvalidUtf8PropagatesAnEncodingError(): void
    {
        $this->expectException(JsonException::class);

        ProblemResponseFactory::create(
            400,
            'Bad Request',
            "\xFF",
        );
    }

    #[DataProvider('invalidStatuses')]
    public function testInvalidStatusesAreRejected(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProblemResponseFactory::create($status, 'Example');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'success' => [200];
        yield 'below error range' => [399];
        yield 'above error range' => [600];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidTitles')]
    public function testBlankTitlesAreRejected(string $title): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProblemResponseFactory::create(400, $title);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTitles(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\r\n"];
    }
}
