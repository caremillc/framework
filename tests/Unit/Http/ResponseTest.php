<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testDefaultsToAnEmptySuccessfulResponse(): void
    {
        $response = new Response();

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validStatusBoundaries(): iterable
    {
        yield 'minimum' => [200];
        yield 'maximum' => [599];
    }

    #[DataProvider('validStatusBoundaries')]
    public function testAcceptsStatusBoundaries(int $status): void
    {
        $response = new Response(status: $status);

        self::assertSame($status, $response->status());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'zero' => [0];
        yield 'informational' => [100];
        yield 'below final range' => [199];
        yield 'above range' => [600];
    }

    #[DataProvider('invalidStatuses')]
    public function testRejectsUnsupportedStatus(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Final response status must be between 200 and 599.',
        );

        new Response(status: $status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function bodylessStatuses(): iterable
    {
        yield 'no content' => [204];
        yield 'reset content' => [205];
        yield 'not modified' => [304];
    }

    #[DataProvider('bodylessStatuses')]
    public function testAcceptsEmptyBodyForBodylessStatus(int $status): void
    {
        $response = new Response(status: $status);

        self::assertSame($status, $response->status());
        self::assertSame('', $response->body());
    }

    #[DataProvider('bodylessStatuses')]
    public function testRejectsContentForBodylessStatus(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Response status %d requires an empty body.',
                $status,
            ),
        );

        new Response(status: $status, body: '0');
    }

    public function testBodyUpdatePreservesOriginalResponse(): void
    {
        $original = new Response(status: 201, body: 'Original');
        $updated = $original->withBody('Updated');

        self::assertNotSame($original, $updated);
        self::assertSame('Original', $original->body());
        self::assertSame('Updated', $updated->body());
        self::assertSame(201, $updated->status());
    }

    public function testStatusUpdatePreservesOriginalResponse(): void
    {
        $original = new Response(status: 200, body: 'Content');
        $updated = $original->withStatus(202);

        self::assertNotSame($original, $updated);
        self::assertSame(200, $original->status());
        self::assertSame(202, $updated->status());
        self::assertSame('Content', $updated->body());
    }

    public function testCannotAddBodyToNoContentResponse(): void
    {
        $response = new Response(status: 204);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Response status 204 requires an empty body.',
        );

        $response->withBody('Content');
    }

    public function testStatusChangeCannotSilentlyDiscardBody(): void
    {
        $original = new Response(body: 'Content');

        try {
            $original->withStatus(204);

            self::fail('A status change must not discard content.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Response status 204 requires an empty body.',
                $exception->getMessage(),
            );
        }

        self::assertSame(200, $original->status());
        self::assertSame('Content', $original->body());

        $updated = $original->withBody('')->withStatus(204);

        self::assertSame(204, $updated->status());
        self::assertSame('', $updated->body());
    }

    public function testBodyBytesArePreservedExactly(): void
    {
        $body = "0\0\r\n";
        $response = new Response(body: $body);

        self::assertSame($body, $response->body());
    }
}
