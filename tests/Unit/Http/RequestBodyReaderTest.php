<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Exception\RequestBodyTooLargeException;
use Flint\Http\RequestBodyReader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RequestBodyReaderTest extends TestCase
{
    /**
     * @var list<resource>
     */
    private array $streams = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->streams as $stream) {
                fclose($stream);
            }

            $this->streams = [];
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodies(): iterable
    {
        yield 'empty' => [''];
        yield 'zero string' => ['0'];
        yield 'binary' => ["\0\xFF\r\n"];
        yield 'UTF-8' => ["Caf\xC3\xA9"];
    }

    #[DataProvider('bodies')]
    public function testPreservesBytesAndLeavesStreamOpen(string $body): void
    {
        $stream = $this->stream($body);

        $result = new RequestBodyReader(maxBytes: 32)->read($stream);

        self::assertSame($body, $result);

        // Reading again verifies that the caller's stream remains open.
        self::assertSame('', stream_get_contents($stream));
    }

    public function testAcceptsBodyExactlyAtLimit(): void
    {
        $stream = $this->stream('12345');

        self::assertSame(
            '12345',
            new RequestBodyReader(maxBytes: 5)->read($stream),
        );
    }

    public function testRejectsBodyExceedingLimit(): void
    {
        $stream = $this->stream('123456');

        $this->expectException(RequestBodyTooLargeException::class);
        $this->expectExceptionMessage(
            'The request body exceeds the configured byte limit.',
        );

        new RequestBodyReader(maxBytes: 5)->read($stream);
    }

    public function testZeroLimitAcceptsEmptyBody(): void
    {
        $stream = $this->stream('');

        self::assertSame(
            '',
            new RequestBodyReader(maxBytes: 0)->read($stream),
        );
    }

    public function testZeroLimitRejectsOneByte(): void
    {
        $stream = $this->stream('0');

        $this->expectException(RequestBodyTooLargeException::class);

        new RequestBodyReader(maxBytes: 0)->read($stream);
    }

    public function testReadsFromCurrentPosition(): void
    {
        $stream = $this->stream('prefix-body');

        self::assertSame('prefix-', fread($stream, 7));

        self::assertSame(
            'body',
            new RequestBodyReader(maxBytes: 4)->read($stream),
        );
    }

    public function testRejectsNegativeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The request body limit must not be negative.',
        );

        new RequestBodyReader(maxBytes: -1);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSources(): iterable
    {
        yield 'null' => [null];
        yield 'path string' => ['php://input'];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('invalidSources')]
    public function testRejectsNonStreamSource(mixed $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The request body source must be an open stream.',
        );

        new RequestBodyReader()->read($source);
    }

    public function testReadsAcrossMultipleChunks(): void
    {
        $body = str_repeat('x', 20_000);
        $stream = $this->stream($body);

        self::assertSame(
            $body,
            new RequestBodyReader(maxBytes: 20_000)->read($stream),
        );
    }

    /**
     * @return resource
     */
    private function stream(string $body)
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            self::fail('Could not create the body fixture stream.');
        }

        $this->streams[] = $stream;

        self::assertSame(strlen($body), fwrite($stream, $body));
        self::assertTrue(rewind($stream));

        return $stream;
    }
}
