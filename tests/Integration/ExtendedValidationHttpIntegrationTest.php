<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Application;
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\ProblemExceptionHandler;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\ValidationResult;
use Careminate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtendedValidationHttpIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, list<string>>
     * }>
     */
    public static function rejectedJsonCases(): iterable
    {
        yield 'unknown role' => [
            '{"title":"Go","role":"owner"}',
            ['role' => ['enum']],
        ];

        yield 'duplicate tags' => [
            '{"title":"Go","role":"reader","tags":["a","a"]}',
            ['tags' => ['unique_items']],
        ];

        yield 'multibyte title exceeds byte limit' => [
            '{"title":"💻💻","role":"reader"}',
            ['title' => ['max_bytes']],
        ];

        yield 'nested enum failure' => [
            '{"title":"Go","role":"reader","profile":{"theme":"blue"}}',
            ['profile.theme' => ['enum']],
        ];

        yield 'integer enum failure' => [
            '{"title":"Go","role":"reader","retries":2}',
            ['retries' => ['enum']],
        ];

        yield 'all errors redacted' => [
            '{"title":"💻💻","role":"owner","tags":["x","x"],"profile":{"theme":"blue"},"retries":2,"secret":"SENSITIVE"}',
            [
                '_input' => ['unknown_fields'],
                'title' => ['max_bytes'],
                'role' => ['enum'],
                'tags' => ['unique_items'],
                'profile.theme' => ['enum'],
                'retries' => ['enum'],
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('rejectedJsonCases')]
    public function testInvalidBusinessDataReturnsSafe422(
        string $json,
        array $expected,
    ): void {
        [$response, $output] = self::send(
            self::kernel(),
            $json,
        );

        self::assertSame(422, $response->status);
        self::assertSame(422, $output->status());

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

        self::assertSame(
            $response->body,
            $output->body(),
        );

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);
        self::assertSame(422, $problem['status']);
        self::assertSame($expected, $problem['errors']);

        self::assertStringNotContainsString(
            'SENSITIVE',
            $response->body,
        );

        self::assertStringNotContainsString(
            'secret',
            $response->body,
        );
    }

    public function testValidJsonRoundTripPreservesTypes(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            '{"title":"Go","role":"reader","tags":["a","b"],"profile":{"theme":"light"},"retries":1}',
        );

        self::assertSame(201, $response->status);
        self::assertSame(201, $output->status());

        self::assertSame(
            'application/json',
            $response->header('Content-Type'),
        );

        self::assertSame(
            [
                'data' => [
                    'title' => 'Go',
                    'role' => 'reader',
                    'tags' => ['a', 'b'],
                    'profile' => ['theme' => 'light'],
                    'retries' => 1,
                ],
            ],
            json_decode(
                $response->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame($response->body, $output->body());
    }

    public function testMalformedJsonRemains400(): void
    {
        [$response] = self::send(
            self::kernel(),
            '{"title":]',
        );

        self::assertSame(400, $response->status);

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);
        self::assertSame(400, $problem['status']);
        self::assertArrayNotHasKey('errors', $problem);
    }

    public function testUnsupportedMediaTypeRemains415(): void
    {
        [$response] = self::send(
            self::kernel(),
            '{"title":"Go","role":"reader"}',
            'text/plain',
        );

        self::assertSame(415, $response->status);

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);
        self::assertSame(415, $problem['status']);
        self::assertArrayNotHasKey('errors', $problem);
    }

    public function testInvalidRequestDoesNotPoisonNextRequest(): void
    {
        $kernel = self::kernel();

        [$invalid] = self::send(
            $kernel,
            '{"title":"Go","role":"root"}',
        );

        [$valid] = self::send(
            $kernel,
            '{"title":"Go","role":"reader"}',
        );

        self::assertSame(422, $invalid->status);
        self::assertSame(201, $valid->status);

        self::assertSame(
            [
                'data' => [
                    'title' => 'Go',
                    'role' => 'reader',
                ],
            ],
            json_decode(
                $valid->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testHeadValidationResponseHasNoBody(): void
    {
        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            self::kernel(),
            new ResponseEmitter($output),
        );

        $response = $runner->runSafely([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/api/invalid',
        ]);

        self::assertSame(422, $response->status);
        self::assertSame(422, $output->status());

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        self::assertSame('', $response->body);
        self::assertSame('', $output->body());
    }

    private static function kernel(): Kernel
    {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ProblemExceptionHandler(),
        );

        $parser = new JsonBodyParser();
        $validator = new Validator();

        $schema = [
            'title' => [
                'type' => 'string',
                'required' => true,
                'maxBytes' => 6,
            ],
            'role' => [
                'type' => 'string',
                'required' => true,
                'enum' => ['reader', 'writer'],
            ],
            'tags' => [
                'type' => 'array',
                'uniqueItems' => true,
                'items' => ['type' => 'string'],
            ],
            'profile' => [
                'type' => 'object',
                'fields' => [
                    'theme' => [
                        'type' => 'string',
                        'enum' => ['light', 'dark'],
                    ],
                ],
            ],
            'retries' => [
                'type' => 'integer',
                'enum' => [0, 1, 3],
            ],
        ];

        $kernel->router()->post(
            '/api/tasks',
            static function (
                Request $request,
            ) use (
                $parser,
                $validator,
                $schema,
            ): Response {
                $input = $parser->parseObject($request);

                $result = $validator->validate(
                    $input,
                    $schema,
                );

                if (! $result->isValid()) {
                    throw new ValidationException($result);
                }

                return new Response(
                    201,
                    ['Content-Type' => 'application/json'],
                    json_encode(
                        ['data' => $result->validated()],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                );
            },
        );

        $kernel->router()->get(
            '/api/invalid',
            static function (Request $request): Response {
                throw new ValidationException(
                    new ValidationResult(
                        [],
                        ['role' => ['enum']],
                    ),
                );
            },
        );

        return $kernel;
    }

    /**
     * @return array{Response, BufferedResponseOutput}
     */
    private static function send(
        Kernel $kernel,
        string $body,
        string $contentType = 'application/json',
    ): array {
        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        $response = $runner->runSafely(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/api/tasks',
                'CONTENT_TYPE' => $contentType,
                'CONTENT_LENGTH' => (string) strlen($body),
            ],
            $body,
        );

        return [$response, $output];
    }
}
