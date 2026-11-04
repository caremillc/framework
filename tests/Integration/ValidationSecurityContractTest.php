<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Body\ValidatedJsonBodyParser;
use Careminate\Http\Exception\HttpException;
use Careminate\Http\Exception\ValidationProblemResponseFactory;
use Careminate\Http\Request;
use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\Validator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationSecurityContractTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, list<string>>
     * }>
     */
    public static function invalidBusinessCases(): iterable
    {
        yield 'missing required field' => [
            '{"status":"open"}',
            ['title' => ['required']],
        ];

        yield 'nonnullable null' => [
            '{"title":null,"status":"open"}',
            ['title' => ['type']],
        ];

        yield 'blank title' => [
            '{"title":"   ","status":"open"}',
            ['title' => ['blank']],
        ];

        yield 'title type not coerced' => [
            '{"title":4,"status":"open"}',
            ['title' => ['type']],
        ];

        yield 'UTF-8 byte limit' => [
            '{"title":"😀😀","status":"open"}',
            ['title' => ['max_bytes']],
        ];

        yield 'string enum case sensitive' => [
            '{"title":"Go","status":"OPEN"}',
            ['status' => ['enum']],
        ];

        yield 'integer enum strict' => [
            '{"title":"Go","status":"open","priority":2}',
            ['priority' => ['enum']],
        ];

        yield 'numeric string rejected' => [
            '{"title":"Go","status":"open","priority":"1"}',
            ['priority' => ['type']],
        ];

        yield 'range and enum collected' => [
            '{"title":"Go","status":"open","priority":5}',
            ['priority' => ['max', 'enum']],
        ];

        yield 'duplicate list item' => [
            '{"title":"Go","status":"open","tags":["a","a"]}',
            ['tags' => ['unique_items']],
        ];

        yield 'too many list items' => [
            '{"title":"Go","status":"open","tags":["a","b","c","d"]}',
            ['tags' => ['max_items']],
        ];

        yield 'invalid indexed item' => [
            '{"title":"Go","status":"open","tags":["a",42]}',
            ['tags[1]' => ['type']],
        ];

        yield 'unknown top-level field redacted' => [
            '{"title":"Go","status":"open","api_key":"PRIVATE"}',
            ['_input' => ['unknown_fields']],
        ];

        yield 'unknown nested field redacted' => [
            '{"title":"Go","status":"open",'
                . '"profile":{"source":"web","secret":"PRIVATE"}}',
            ['profile._input' => ['unknown_fields']],
        ];

        yield 'nested enum' => [
            '{"title":"Go","status":"open","profile":{"source":"sms"}}',
            ['profile.source' => ['enum']],
        ];
    }

    /**
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('invalidBusinessCases')]
    public function testBusinessFailuresUseOnlySafeErrorCodes(
        string $body,
        array $expected,
    ): void {
        try {
            self::parser()->parse(
                self::request($body),
                self::schema(),
            );

            self::fail('Invalid business data must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());

            self::assertSame(
                $expected,
                $exception->errors(),
            );

            self::assertSame(
                'Input validation failed.',
                $exception->getMessage(),
            );

            $encoded = json_encode(
                $exception->errors(),
                JSON_THROW_ON_ERROR,
            );

            self::assertStringNotContainsString(
                'PRIVATE',
                $encoded,
            );

            self::assertStringNotContainsString(
                'api_key',
                $encoded,
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     ?string,
     *     int,
     *     int
     * }>
     */
    public static function parserFailureCases(): iterable
    {
        yield 'syntax error' => [
            '{"title":}',
            'application/json',
            1048576,
            400,
        ];

        yield 'invalid root array' => [
            '[]',
            'application/json',
            1048576,
            400,
        ];

        yield 'duplicate JSON name' => [
            '{"title":"Go","title":"No","status":"open"}',
            'application/json',
            1048576,
            400,
        ];

        yield 'escaped duplicate name' => [
            '{"title":"Go","status":"open","s\u0074atus":"closed"}',
            'application/json',
            1048576,
            400,
        ];

        yield 'unsupported media type' => [
            '{"title":"Go","status":"open"}',
            'text/plain',
            1048576,
            415,
        ];

        yield 'missing media type' => [
            '{"title":"Go","status":"open"}',
            null,
            1048576,
            415,
        ];

        yield 'body exceeds parser limit' => [
            '{"title":"Go","status":"open"}',
            'application/json',
            8,
            413,
        ];
    }

    #[DataProvider('parserFailureCases')]
    public function testParsingFailuresNeverBecome422(
        string $body,
        ?string $mediaType,
        int $maxBytes,
        int $expectedStatus,
    ): void {
        try {
            self::parser($maxBytes)->parse(
                self::request($body, $mediaType),
                self::schema(),
            );

            self::fail('Invalid JSON input must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(
                $expectedStatus,
                $exception->status,
            );
        }
    }

    public function testValidInputPreservesTypesAndOnlyDeclaredFields(): void
    {
        $body = '{"title":"Go","status":"open","priority":1,'
            . '"tags":["a","b"],"profile":{"source":"cli"}}';

        self::assertSame(
            [
                'title' => 'Go',
                'status' => 'open',
                'priority' => 1,
                'tags' => ['a', 'b'],
                'profile' => ['source' => 'cli'],
            ],
            self::parser()->parse(
                self::request($body),
                self::schema(),
            ),
        );
    }

    public function testUnknownFieldsCanBeFilteredRecursively(): void
    {
        $body = '{"title":"Go","status":"open","api_key":"PRIVATE",'
            . '"profile":{"source":"web","secret":"PRIVATE"}}';

        self::assertSame(
            [
                'title' => 'Go',
                'status' => 'open',
                'profile' => ['source' => 'web'],
            ],
            self::parser()->parse(
                self::request($body),
                self::schema(),
                rejectUnknown: false,
            ),
        );
    }

    public function testAbsentOptionalFieldDoesNotBypassSchemaValidation(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        self::parser()->parse(
            self::request(
                '{"title":"Go","status":"open"}',
            ),
            [
                ...self::schema(),
                'optional' => [
                    'type' => 'integer',
                    'enum' => [],
                ],
            ],
        );
    }

    public function test422ResponseDoesNotEchoInvalidValues(): void
    {
        $body = '{"title":"Go","status":"open",'
            . '"token":"PRIVATE"}';

        try {
            self::parser()->parse(
                self::request($body),
                self::schema(),
            );

            self::fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $response = new ValidationProblemResponseFactory()
                ->create($exception);

            self::assertSame(422, $response->status);

            self::assertSame(
                'application/problem+json',
                $response->header('Content-Type'),
            );

            self::assertSame(
                'no-store',
                $response->header('Cache-Control'),
            );

            self::assertSame(
                'nosniff',
                $response->header('X-Content-Type-Options'),
            );

            self::assertStringNotContainsString(
                'PRIVATE',
                $response->body,
            );

            self::assertStringNotContainsString(
                'token',
                $response->body,
            );

            $problem = json_decode(
                $response->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            self::assertIsArray($problem);

            self::assertSame(
                422,
                $problem['status'],
            );

            self::assertSame(
                ['_input' => ['unknown_fields']],
                $problem['errors'],
            );
        }
    }

    public function testFailedResultCannotExposePartialValidatedData(): void
    {
        $result = new Validator()->validate(
            [
                'title' => 'Go',
                'status' => 'invalid',
            ],
            self::schema(),
        );

        self::assertFalse($result->isValid());

        self::assertSame(
            ['status' => ['enum']],
            $result->errors(),
        );

        $this->expectException(LogicException::class);

        $result->validated();
    }

    public function testParserHasNoErrorStateAcrossRequests(): void
    {
        $parser = self::parser();

        try {
            $parser->parse(
                self::request(
                    '{"title":"Go","status":"INVALID"}',
                ),
                self::schema(),
            );

            self::fail('Expected the initial request to fail.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['status' => ['enum']],
                $exception->errors(),
            );
        }

        self::assertSame(
            [
                'title' => 'Go',
                'status' => 'open',
            ],
            $parser->parse(
                self::request(
                    '{"title":"Go","status":"open"}',
                ),
                self::schema(),
            ),
        );
    }

    public function testUtf8CodePointAndByteBoundsRemainDistinct(): void
    {
        $result = new Validator()->validate(
            ['value' => 'éé'],
            [
                'value' => [
                    'type' => 'string',
                    'maxLength' => 2,
                    'maxBytes' => 3,
                ],
            ],
        );

        self::assertSame(
            ['value' => ['max_bytes']],
            $result->errors(),
        );
    }

    public function testNullListMemberRequiresExplicitNullableRule(): void
    {
        $result = new Validator()->validate(
            ['tags' => ['a', null]],
            [
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        );

        self::assertSame(
            ['tags[1]' => ['type']],
            $result->errors(),
        );
    }

    private static function parser(
        int $maxBytes = 1048576,
    ): ValidatedJsonBodyParser {
        return new ValidatedJsonBodyParser(
            new JsonBodyParser($maxBytes),
            new Validator(),
        );
    }

    private static function request(
        string $body,
        ?string $mediaType = 'application/json',
    ): Request {
        return new Request(
            'POST',
            '/api/items',
            $mediaType === null
                ? []
                : ['Content-Type' => $mediaType],
            $body,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function schema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'required' => true,
                'notBlank' => true,
                'maxBytes' => 6,
            ],

            'status' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['open', 'closed'],
            ],

            'priority' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 3,
                'enum' => [1, 3],
            ],

            'tags' => [
                'type' => 'array',
                'maxItems' => 3,
                'uniqueItems' => true,
                'items' => [
                    'type' => 'string',
                    'maxBytes' => 8,
                ],
            ],

            'profile' => [
                'type' => 'object',
                'fields' => [
                    'source' => [
                        'type' => 'string',
                        'enum' => ['web', 'cli'],
                    ],
                ],
            ],
        ];
    }
}
