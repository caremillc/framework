<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseHardeningTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function bodylessStatuses(): iterable
    {
        yield 'informational'       => [100];
        yield 'switching protocols' => [101];
        yield 'no content'          => [204];
        yield 'not modified'        => [304];
    }

    #[DataProvider('bodylessStatuses')]
    public function testBodylessStatusAcceptsEmptyBody(
        int $status,
    ): void {
        $response = new Response($status);

        self::assertSame('', $response->body);
    }

    #[DataProvider('bodylessStatuses')]
    public function testBodylessStatusRejectsContent(
        int $status,
    ): void {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Response(
            $status,
            body: 'Unexpected content',
        );
    }

    public function testWithStatusPreservesBodyInvariant(): void
    {
        $response = new Response(
            200,
            body: 'Existing content',
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $response->withStatus(204);
    }

    public function testClearingBodyAllows204(): void
    {
        $response = new Response(
            200,
            body: 'Existing content',
        );

        $updated = $response
            ->withBody('')
            ->withStatus(204);

        self::assertSame(204, $updated->status);
        self::assertSame('', $updated->body);
    }

    public function testWithBodyPreservesBodyInvariant(): void
    {
        $response = new Response(304);

        $this->expectException(
            InvalidArgumentException::class
        );

        $response->withBody('Unexpected');
    }

    public function testValidResponseRemainsImmutable(): void
    {
        $original = new Response(
            200,
            body: 'Original',
        );

        $updated = $original->withBody('Updated');

        self::assertSame('Original', $original->body);
        self::assertSame('Updated', $updated->body);
    }
}
