<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Body;

use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Body\ValidatedJsonBodyParser;
use Careminate\Http\Exception\HttpException;
use Careminate\Http\Request;
use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatedJsonBodyParserTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string, string, array<string, mixed>
     * }>
     */
    public static function validPayloadCases(): iterable
    {
        yield 'minimum object' => [
            '{"title":"Go","role":"reader"}',
            'application/json',
            ['title' => 'Go', 'role' => 'reader'],
        ];

        yield 'optional integer' => [
            '{"title":"Build","role":"writer","priority":5}',
            'application/json',
            ['title' => 'Build', 'role' => 'writer', 'priority' => 5],
        ];

        yield 'unique list' => [
            '{"title":"Go","role":"reader","tags":["a","b"]}',
            'application/json',
            [
                'title' => 'Go',
                'role' => 'reader',
                'tags' => ['a', 'b'],
            ],
        ];

        yield 'nested object' => [
            '{"title":"Go","role":"reader","profile":{"email":"a@b.test"}}',
            'application/json',
            [
                'title' => 'Go',
                'role' => 'reader',
                'profile' => ['email' => 'a@b.test'],
            ],
        ];

        yield 'Unicode string' => [
            '{"title":"مرحبا","role":"reader"}',
            'application/json',
            ['title' => 'مرحبا', 'role' => 'reader'],
        ];

        yield 'vendor JSON content type' => [
            '{"title":"Go","role":"reader"}',
            'application/vnd.example+json',
            ['title' => 'Go', 'role' => 'reader'],
        ];

        yield 'UTF-8 charset' => [
            '{"title":"Go","role":"reader"}',
            'application/json; charset=utf-8',
            ['title' => 'Go', 'role' => 'reader'],
        ];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('validPayloadCases')]
    public function testValidPayloadIsDecodedValidatedAndReturned(
        string $body,
        string $contentType,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            self::parser()->parse(
                self::request($body, $contentType),
                self::schema(),
            ),
        );
    }

    /**
     * @return iterable<string, array{
     *     string, array<string, list<string>>
     * }>
     */
    public static function fieldFailureCases(): iterable
    {
        yield 'missing title' => [
            '{"role":"reader"}',
            ['title' => ['required']],
        ];

        yield 'missing role' => [
            '{"title":"Go"}',
            ['role' => ['required']],
        ];

        yield 'blank title' => [
            '{"title":"  ","role":"reader"}',
            ['title' => ['blank']],
        ];

        yield 'short title' => [
            '{"title":"A","role":"reader"}',
            ['title' => ['min_length']],
        ];

        yield 'title type not coerced' => [
            '{"title":2,"role":"reader"}',
            ['title' => ['type']],
        ];

        yield 'priority out of range' => [
            '{"title":"Go","role":"reader","priority":6}',
            ['priority' => ['max']],
        ];

        yield 'priority numeric string' => [
            '{"title":"Go","role":"reader","priority":"2"}',
            ['priority' => ['type']],
        ];

        yield 'role enum' => [
            '{"title":"Go","role":"owner"}',
            ['role' => ['enum']],
        ];

        yield 'duplicate list member' => [
            '{"title":"Go","role":"reader","tags":["a","a"]}',
            ['tags' => ['unique_items']],
        ];

        yield 'unexpected field name redacted' => [
            '{"title":"Go","role":"reader","private_token":"secret"}',
            ['_input' => ['unknown_fields']],
        ];

        yield 'nested required field' => [
            '{"title":"Go","role":"reader","profile":{}}',
            ['profile.email' => ['required']],
        ];

        yield 'nested unknown marker' => [
            '{"title":"Go","role":"reader","profile":{"email":"a","admin":true}}',
            ['profile._input' => ['unknown_fields']],
        ];
    }

    /** @param array<string, list<string>> $expected */
    #[DataProvider('fieldFailureCases')]
    public function testInvalidFieldsRaiseSafeValidationException(
        string $body,
        array $expected,
    ): void {
        try {
            self::parser()->parse(
                self::request($body),
                self::schema(),
            );

            self::fail(
                'Invalid fields must not produce validated data.',
            );
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame($expected, $exception->errors());

            self::assertSame(
                'Input validation failed.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     string, ?string, int, int
     * }>
     */
    public static function parserFailureCases(): iterable
    {
        yield 'malformed JSON' => [
            '{"title":}',
            'application/json',
            1048576,
            400,
        ];

        yield 'array root' => [
            '[1,2]',
            'application/json',
            1048576,
            400,
        ];

        yield 'empty body' => [
            '',
            'application/json',
            1048576,
            400,
        ];

        yield 'duplicate JSON name' => [
            '{"title":"Go","title":"No"}',
            'application/json',
            1048576,
            400,
        ];

        yield 'escaped duplicate name' => [
            '{"title":"Go","\\u0074itle":"No"}',
            'application/json',
            1048576,
            400,
        ];

        yield 'unsupported media' => [
            '{"title":"Go"}',
            'text/plain',
            1048576,
            415,
        ];

        yield 'missing media' => [
            '{"title":"Go"}',
            null,
            1048576,
            415,
        ];

        yield 'JSON parser byte limit' => [
            '{"title":"Go","role":"reader"}',
            'application/json',
            8,
            413,
        ];
    }

    #[DataProvider('parserFailureCases')]
    public function testParserErrorsRemain400413Or415(
        string $body,
        ?string $contentType,
        int $maxBytes,
        int $expectedStatus,
    ): void {
        try {
            self::parser($maxBytes)->parse(
                self::request($body, $contentType),
                self::schema(),
            );

            self::fail('Expected JSON parsing to fail.');
        } catch (HttpException $exception) {
            self::assertSame(
                $expectedStatus,
                $exception->status,
            );
        }
    }

    public function testUnknownFieldsCanBeFilteredAtEveryObjectLevel(): void
    {
        $body = '{"title":"Go","role":"reader","extra":"hidden",'
            . '"profile":{"email":"a","admin":true}}';

        self::assertSame(
            [
                'title' => 'Go',
                'role' => 'reader',
                'profile' => ['email' => 'a'],
            ],
            self::parser()->parse(
                self::request($body),
                self::schema(),
                rejectUnknown: false,
            ),
        );
    }

    public function testInvalidSchemaIsRejectedEvenIfOptionalFieldIsAbsent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::parser()->parse(
            self::request('{"title":"Go","role":"reader"}'),
            [
                ...self::schema(),
                'optional' => [
                    'type' => 'integer',
                    'enum' => [],
                ],
            ],
        );
    }

    public function testStrictIntegerEnumRemainsEnforced(): void
    {
        try {
            self::parser()->parse(
                self::request('{"value":3}'),
                [
                    'value' => [
                        'type' => 'integer',
                        'enum' => [0, 2, 4],
                    ],
                ],
            );

            self::fail('Integer enum must reject 3.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['value' => ['enum']],
                $exception->errors(),
            );
        }
    }

    public function testValidRequestAfterRejectedRequestDoesNotShareState(): void
    {
        $parser = self::parser();

        try {
            $parser->parse(
                self::request('{"title":"Go","role":"root"}'),
                self::schema(),
            );

            self::fail('Invalid enum must fail.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['role' => ['enum']],
                $exception->errors(),
            );
        }

        self::assertSame(
            [
                'title' => 'Next',
                'role' => 'reader',
            ],
            $parser->parse(
                self::request('{"title":"Next","role":"reader"}'),
                self::schema(),
            ),
        );
    }

    public function testOriginalRequestBodyIsNotModified(): void
    {
        $request = self::request(
            '{"title":"Go","role":"reader"}',
        );

        $body = $request->body;

        self::parser()->parse($request, self::schema());

        self::assertSame($body, $request->body);
        self::assertSame('POST', $request->method);
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
        ?string $contentType = 'application/json',
    ): Request {
        return new Request(
            'POST',
            '/api/items',
            $contentType === null
                ? []
                : ['Content-Type' => $contentType],
            $body,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private static function schema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'required' => true,
                'notBlank' => true,
                'minLength' => 2,
                'maxBytes' => 12,
            ],
            'role' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['reader', 'writer'],
            ],
            'priority' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 5,
            ],
            'tags' => [
                'type' => 'array',
                'uniqueItems' => true,
                'items' => ['type' => 'string'],
            ],
            'profile' => [
                'type' => 'object',
                'fields' => [
                    'email' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }
}
