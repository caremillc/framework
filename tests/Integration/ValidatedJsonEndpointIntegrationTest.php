<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Application;
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Body\ValidatedJsonBodyParser;
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
use RuntimeException;

final class ValidatedJsonEndpointIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string, array<string, mixed>
     * }>
     */
    public static function successfulCases(): iterable
    {
        yield 'required fields' => [
            '{"title":"Go","role":"reader"}',
            ['title' => 'Go', 'role' => 'reader'],
        ];

        yield 'integer and boolean types' => [
            '{"title":"Test","role":"writer","priority":3,"active":false}',
            [
                'title' => 'Test',
                'role' => 'writer',
                'priority' => 3,
                'active' => false,
            ],
        ];

        yield 'unique list' => [
            '{"title":"Go","role":"reader","tags":["red","blue"]}',
            [
                'title' => 'Go',
                'role' => 'reader',
                'tags' => ['red', 'blue'],
            ],
        ];

        yield 'nested object' => [
            '{"title":"Go","role":"reader","profile":{"name":"Ava"}}',
            [
                'title' => 'Go',
                'role' => 'reader',
                'profile' => ['name' => 'Ava'],
            ],
        ];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('successfulCases')]
    public function testValidJsonReturns201AndOnlyValidatedFields(
        string $body,
        array $expected,
    ): void {
        [$response, $output] = self::send(
            self::kernel(),
            $body,
        );

        self::assertSame(201, $response->status);
        self::assertSame(201, $output->status());
        self::assertSame($response->body, $output->body());

        self::assertSame(
            'application/json; charset=utf-8',
            $response->header('Content-Type'),
        );

        self::assertSame(
            ['data' => $expected],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     ?string,
     *     int,
     *     ?array<string, list<string>>
     * }>
     */
    public static function failureCases(): iterable
    {
        yield 'missing title' => [
            '{"role":"reader"}',
            'application/json',
            422,
            ['title' => ['required']],
        ];

        yield 'role enum' => [
            '{"title":"Go","role":"admin"}',
            'application/json',
            422,
            ['role' => ['enum']],
        ];

        yield 'integer range' => [
            '{"title":"Go","role":"reader","priority":10}',
            'application/json',
            422,
            ['priority' => ['max']],
        ];

        yield 'boolean not coerced' => [
            '{"title":"Go","role":"reader","active":1}',
            'application/json',
            422,
            ['active' => ['type']],
        ];

        yield 'duplicate tags' => [
            '{"title":"Go","role":"reader","tags":["a","a"]}',
            'application/json',
            422,
            ['tags' => ['unique_items']],
        ];

        yield 'nested required name' => [
            '{"title":"Go","role":"reader","profile":{}}',
            'application/json',
            422,
            ['profile.name' => ['required']],
        ];

        yield 'nested unknown redacted' => [
            '{"title":"Go","role":"reader","profile":{"name":"Ava","secret":"PRIVATE"}}',
            'application/json',
            422,
            ['profile._input' => ['unknown_fields']],
        ];

        yield 'root unknown redacted' => [
            '{"title":"Go","role":"reader","api_key":"PRIVATE"}',
            'application/json',
            422,
            ['_input' => ['unknown_fields']],
        ];

        yield 'malformed syntax' => [
            '{"title":}',
            'application/json',
            400,
            null,
        ];

        yield 'duplicate JSON name' => [
            '{"title":"Go","title":"No"}',
            'application/json',
            400,
            null,
        ];

        yield 'JSON root array' => [
            '[1,2]',
            'application/json',
            400,
            null,
        ];

        yield 'unsupported media type' => [
            '{"title":"Go","role":"reader"}',
            'text/plain',
            415,
            null,
        ];

        yield 'missing media type' => [
            '{"title":"Go","role":"reader"}',
            null,
            415,
            null,
        ];
    }

    /** @param ?array<string, list<string>> $expectedErrors */
    #[DataProvider('failureCases')]
    public function testFailureStatusAndResponseContractArePreserved(
        string $body,
        ?string $contentType,
        int $status,
        ?array $expectedErrors,
    ): void {
        [$response, $output] = self::send(
            self::kernel(),
            $body,
            contentType: $contentType,
        );

        self::assertSame($status, $response->status);
        self::assertSame($status, $output->status());
        self::assertSame($response->body, $output->body());

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

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);

        self::assertSame('about:blank', $problem['type']);
        self::assertSame($status, $problem['status']);

        if ($expectedErrors === null) {
            self::assertArrayNotHasKey(
                'errors',
                $problem,
            );
        } else {
            self::assertSame(
                $expectedErrors,
                $problem['errors'],
            );
        }

        self::assertStringNotContainsString(
            'PRIVATE',
            $response->body,
        );

        self::assertStringNotContainsString(
            'api_key',
            $response->body,
        );

        self::assertStringNotContainsString(
            'secret',
            $response->body,
        );
    }

    public function testMalformedContentLengthReturns400WithoutHandlerDispatch(): void
    {
        $calls = 0;
        $kernel = self::kernel($calls);

        [$response, $output] = self::send(
            $kernel,
            '{"title":"Go","role":"reader"}',
            overrides: ['CONTENT_LENGTH' => '1'],
        );

        self::assertSame(400, $response->status);
        self::assertSame(400, $output->status());
        self::assertSame(0, $calls);

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );
    }

    public function testIngressByteLimitReturns413BeforeHandlerDispatch(): void
    {
        $calls = 0;
        $kernel = self::kernel($calls);

        [$response, $output] = self::send(
            $kernel,
            '{"title":"Go","role":"reader"}',
            ingressMaxBytes: 8,
        );

        self::assertSame(413, $response->status);
        self::assertSame(413, $output->status());
        self::assertSame(0, $calls);
    }

    public function testParserByteLimitProduces413Not422(): void
    {
        $calls = 0;

        $kernel = self::kernel(
            $calls,
            jsonMaxBytes: 8,
        );

        [$response] = self::send(
            $kernel,
            '{"title":"Go","role":"reader"}',
        );

        self::assertSame(413, $response->status);
        self::assertSame(1, $calls);

        self::assertStringNotContainsString(
            'errors',
            $response->body,
        );
    }

    public function testRejectedRequestDoesNotContaminateLaterRequests(): void
    {
        $calls = 0;
        $kernel = self::kernel($calls);

        [$invalid] = self::send(
            $kernel,
            '{"title":"Go","role":"root"}',
        );

        [$valid, $validOutput] = self::send(
            $kernel,
            '{"title":"Next","role":"reader"}',
        );

        [$health, $healthOutput] = self::send(
            $kernel,
            '',
            method: 'GET',
            target: '/api/health',
        );

        self::assertSame(422, $invalid->status);
        self::assertSame(201, $valid->status);
        self::assertSame(200, $health->status);
        self::assertSame(2, $calls);

        self::assertSame(
            [
                'data' => [
                    'title' => 'Next',
                    'role' => 'reader',
                ],
            ],
            json_decode(
                $validOutput->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame(
            'healthy',
            $healthOutput->body(),
        );
    }

    public function testAutomaticOptionsDoesNotDispatchPostHandler(): void
    {
        $calls = 0;
        $kernel = self::kernel($calls);

        [$response, $output] = self::send(
            $kernel,
            '',
            method: 'OPTIONS',
        );

        self::assertSame(204, $response->status);
        self::assertSame(204, $output->status());

        self::assertSame(
            'OPTIONS, POST',
            $response->header('Allow'),
        );

        self::assertSame('', $output->body());
        self::assertSame(0, $calls);
    }

    public function testRouting404And405RetainTheirExistingContracts(): void
    {
        $calls = 0;
        $kernel = self::kernel($calls);

        [$notFound] = self::send(
            $kernel,
            '',
            method: 'GET',
            target: '/api/missing',
        );

        [$notAllowed] = self::send(
            $kernel,
            '',
            method: 'GET',
            target: '/api/tickets',
        );

        self::assertSame(404, $notFound->status);
        self::assertSame(405, $notAllowed->status);

        self::assertSame(
            'OPTIONS, POST',
            $notAllowed->header('Allow'),
        );

        self::assertNotSame(
            'application/problem+json',
            $notFound->header('Content-Type'),
        );

        self::assertNotSame(
            'application/problem+json',
            $notAllowed->header('Content-Type'),
        );

        self::assertSame(0, $calls);
    }

    public function testHeadValidationErrorEmitsNoBody(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            '',
            method: 'HEAD',
            target: '/api/invalid',
        );

        self::assertSame(422, $response->status);
        self::assertSame(422, $output->status());
        self::assertSame('', $response->body);
        self::assertSame('', $output->body());

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );
    }

    public function testHeadSuccessEmitsNoBody(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            '',
            method: 'HEAD',
            target: '/api/health',
        );

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('', $output->body());
    }

    public function testUnexpectedHandlerFailureProducesSanitized500(): void
    {
        [$response] = self::send(
            self::kernel(),
            '',
            method: 'GET',
            target: '/api/crash',
        );

        self::assertSame(500, $response->status);

        self::assertStringNotContainsString(
            'internal-secret',
            $response->body,
        );

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);
        self::assertSame(500, $problem['status']);

        self::assertArrayNotHasKey(
            'errors',
            $problem,
        );
    }

    public function testWithoutProblemMiddlewareValidationFailurePropagates(): void
    {
        $output = new BufferedResponseOutput();

        $kernel = self::kernel(
            withMiddleware: false,
        );

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        try {
            $runner->runSafely(
                [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI' => '/api/tickets',
                    'CONTENT_TYPE' => 'application/json',
                ],
                '{"role":"reader"}',
            );

            self::fail(
                'ValidationException must propagate without middleware.',
            );
        } catch (ValidationException $exception) {
            self::assertSame(
                ['title' => ['required']],
                $exception->errors(),
            );

            self::assertNull($output->status());
        }
    }

    private static function kernel(
        ?int &$calls = null,
        int $jsonMaxBytes = 1048576,
        bool $withMiddleware = true,
    ): Kernel {
        $kernel = new Kernel(new Application());

        if ($withMiddleware) {
            $kernel->middleware(
                new ProblemExceptionHandler(),
            );
        }

        $input = new ValidatedJsonBodyParser(
            new JsonBodyParser($jsonMaxBytes),
            new Validator(),
        );

        $schema = [
            'title' => [
                'type' => 'string',
                'required' => true,
                'notBlank' => true,
                'maxBytes' => 32,
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
            'active' => [
                'type' => 'boolean',
            ],
            'tags' => [
                'type' => 'array',
                'uniqueItems' => true,
                'items' => ['type' => 'string'],
            ],
            'profile' => [
                'type' => 'object',
                'fields' => [
                    'name' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ];

        $kernel->router()->post(
            '/api/tickets',
            static function (
                Request $request,
            ) use ($input, $schema, &$calls): Response {
                if ($calls !== null) {
                    ++$calls;
                }

                $validated = $input->parse(
                    $request,
                    $schema,
                );

                return new Response(
                    201,
                    [
                        'Content-Type' =>
                            'application/json; charset=utf-8',
                    ],
                    json_encode(
                        ['data' => $validated],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                );
            },
        );

        $kernel->router()->get(
            '/api/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
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

        $kernel->router()->get(
            '/api/crash',
            static function (Request $request): Response {
                throw new RuntimeException(
                    'internal-secret',
                );
            },
        );

        return $kernel;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{Response, BufferedResponseOutput}
     */
    private static function send(
        Kernel $kernel,
        string $body,
        string $method = 'POST',
        string $target = '/api/tickets',
        ?string $contentType = 'application/json',
        array $overrides = [],
        int $ingressMaxBytes = 1048576,
    ): array {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
            'CONTENT_LENGTH' => (string) strlen($body),
        ];

        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory($ingressMaxBytes),
            $kernel,
            new ResponseEmitter($output),
        );

        return [
            $runner->runSafely(
                array_replace($server, $overrides),
                $body,
            ),
            $output,
        ];
    }
}
