<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Body;

use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Exception\HttpException;
use Careminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonDuplicateKeyGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function duplicateKeyCases(): iterable
    {
        yield 'adjacent duplicate'                    => [
            '{"a":1,"a":2}',
            true,
        ];

        yield 'separated duplicate'                   => [
            '{"a":1,"b":2,"a":3}',
            true,
        ];

        yield 'escaped Unicode equivalent'            => [
            '{"name":1,"\u006eame":2}',
            true,
        ];

        yield 'escaped slash equivalent'              => [
            '{"a/b":1,"a\/b":2}',
            true,
        ];

        yield 'nested duplicate'                      => [
            '{"outer":{"id":1,"id":2}}',
            true,
        ];

        yield 'duplicate inside array object'         => [
            '{"items":[{"id":1,"id":2}]}',
            true,
        ];

        yield 'literal and escaped Unicode'           => [
            '{"é":1,"\u00e9":2}',
            true,
        ];

        yield 'surrogate pair equivalent'             => [
            '{"😀":1,"\uD83D\uDE00":2}',
            true,
        ];

        yield 'numeric property equivalent'           => [
            '{"1":1,"\u0031":2}',
            true,
        ];

        yield 'escaped null property equivalent'      => [
            '{"\u0000":1,"\u0000":2}',
            true,
        ];

        yield 'nested repeated property'              => [
            '{"outer":{"x":1},"outer":{"y":2}}',
            true,
        ];

        yield 'escaped quote equivalent'              => [
            '{"a\"b":1,"a\u0022b":2}',
            true,
        ];

        yield 'same key in separate array objects'    => [
            '{"items":[{"id":1},{"id":2}]}',
            false,
        ];

        yield 'case-sensitive properties'             => [
            '{"Name":1,"name":2}',
            false,
        ];

        yield 'distinct numeric strings'              => [
            '{"1":1,"01":2}',
            false,
        ];

        yield 'empty object'                          => [
            '{}',
            false,
        ];

        yield 'empty nested containers'               => [
            '{"a":{},"b":[]}',
            false,
        ];

        yield 'same key at different depths'          => [
            '{"id":1,"nested":{"id":2}}',
            false,
        ];

        yield 'nested arrays and literals'            => [
            '{"items":[1,true,null,{"id":2}]}',
            false,
        ];

        yield 'different trailing whitespace in keys' => [
            '{"name":1,"name ":2}',
            false,
        ];
    }

    #[DataProvider('duplicateKeyCases')]
    public function testDuplicateKeyPolicy(
        string $body,
        bool $hasDuplicates,
    ): void {
        $parser  = new JsonBodyParser();
        $request = self::request($body);

        if ($hasDuplicates) {
            try {
                $parser->parseObject($request);

                self::fail(
                    'Expected duplicate JSON key rejection.',
                );
            } catch (HttpException $exception) {
                self::assertSame(
                    400,
                    $exception->status,
                );

                self::assertSame(
                    'Duplicate JSON object member.',
                    $exception->getMessage(),
                );
            }

            return;
        }

        self::assertSame(
            json_decode(
                $body,
                true,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            ),
            $parser->parseObject($request),
        );

    }

    public function testDuplicateErrorDoesNotExposePropertyNames(): void
    {
        $body = '{"private_token":"secret","private_token":"other"}';

        try {
            new JsonBodyParser()->parseObject(
                self::request($body),
            );

            self::fail(
                'Expected duplicate JSON key rejection.',
            );
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);

            self::assertStringNotContainsString(
                'private_token',
                $exception->getMessage(),
            );

            self::assertStringNotContainsString(
                'secret',
                $exception->getMessage(),
            );
        }
    }

    public function testMalformedJsonIsRejectedBeforeDuplicateScan(): void
    {
        $this->assertHttpStatus(
            400,
            '{"a":1,"a":',
        );
    }

    public function testOversizedJsonIsRejectedBeforeDuplicateScan(): void
    {
        $this->assertHttpStatus(
            413,
            '{"a":1,"a":2}',
            new JsonBodyParser(5),
        );
    }

    public function testParserRecoversAfterDuplicateKeyRejection(): void
    {
        $parser = new JsonBodyParser();

        try {
            $parser->parseObject(
                self::request('{"id":1,"id":2}'),
            );

            self::fail(
                'Expected duplicate-key rejection.',
            );
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }

        self::assertSame(
            ['id' => 3],
            $parser->parseObject(
                self::request('{"id":3}'),
            ),
        );

        self::assertSame(
            [],
            $parser->parseObject(
                self::request('{}'),
            ),
        );
    }

    private function assertHttpStatus(
        int $expectedStatus,
        string $body,
        ?JsonBodyParser $parser = null,
    ): void {
        try {
            ($parser ?? new JsonBodyParser())->parseObject(
                self::request($body),
            );

            self::fail(
                'Expected an HTTP parsing exception.',
            );
        } catch (HttpException $exception) {
            self::assertSame(
                $expectedStatus,
                $exception->status,
            );
        }
    }

    private static function request(string $body): Request
    {
        return new Request(
            'POST',
            '/',
            ['Content-Type' => 'application/json'],
            $body,
        );
    }
}
