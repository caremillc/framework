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
use RuntimeException;

final class ValidationHttpProblemIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, list<string>>
     * }>
     */
    public static function invalidFieldCases(): iterable
    {
        yield 'required title missing' => [
            '{}',
            ['title' => ['required']],
        ];

        yield 'null title' => [
            '{"title":null}',
            ['title' => ['type']],
        ];

        yield 'integer is not a string' => [
            '{"title":123}',
            ['title' => ['type']],
        ];

        yield 'whitespace title' => [
            '{"title":"  "}',
            ['title' => ['blank']],
        ];

        yield 'minimum length' => [
            '{"title":"A"}',
            ['title' => ['min_length']],
        ];

        yield 'maximum length' => [
            '{"title":"12345678901"}',
            ['title' => ['max_length']],
        ];

        yield 'priority below minimum' => [
            '{"title":"Task","priority":0}',
            ['priority' => ['min']],
        ];

        yield 'priority above maximum' => [
            '{"title":"Task","priority":11}',
            ['priority' => ['max']],
        ];

        yield 'numeric string priority' => [
            '{"title":"Task","priority":"3"}',
            ['priority' => ['type']],
        ];

        yield 'boolean type mismatch' => [
            '{"title":"Task","active":"false"}',
            ['active' => ['type']],
        ];

        yield 'unexpected property' => [
            '{"title":"Task","private_token":"do-not-echo"}',
            ['_input' => ['unknown_fields']],
        ];

        yield 'several field errors together' => [
            '{"title":"  ","priority":99,"active":1,"secret":"private"}',
            [
                '_input' => ['unknown_fields'],
                'title' => ['blank'],
                'priority' => ['max'],
                'active' => ['type'],
            ],
        ];
    }

    /** @param array<string, list<string>> $expectedErrors */
    #[DataProvider('invalidFieldCases')]
    public function testInvalidJsonFieldsProduceOnlyDocumented422Errors(
        string $body,
        array $expectedErrors,
    ): void {
        [$response, $output] = self::send(
            self::kernel(),
            'POST',
            '/api/tasks',
            $body,
        );

        self::assertSame(422, $response->status);
        self::assertSame(422, $output->status());

        self::assertSame(
            $response->body,
            $output->body(),
        );

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
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'errors' => $expectedErrors,
            ],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertStringNotContainsString(
            'private_token',
            $output->body(),
        );

        self::assertStringNotContainsString(
            'do-not-echo',
            $output->body(),
        );

        self::assertStringNotContainsString(
            'secret',
            $output->body(),
        );
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function prevalidationFailureCases(): iterable
    {
        yield 'malformed JSON' => [
            '{"title":}',
            'application/json',
            1048576,
            400,
        ];

        yield 'array root' => [
            '[{"title":"Task"}]',
            'application/json',
            1048576,
            400,
        ];

        yield 'empty JSON body' => [
            '',
            'application/json',
            1048576,
            400,
        ];

        yield 'duplicate JSON member' => [
            '{"title":"First","title":"Second"}',
            'application/json',
            1048576,
            400,
        ];

        yield 'unsupported content type' => [
            '{"title":"Task"}',
            'text/plain',
            1048576,
            415,
        ];

        yield 'JSON parser body limit' => [
            '{"title":"Task"}',
            'application/json',
            8,
            413,
        ];
    }

    #[DataProvider('prevalidationFailureCases')]
    public function testParsingFailuresAreNotMisclassifiedAs422(
        string $body,
        string $contentType,
        int $parserMaxBytes,
        int $expectedStatus,
    ): void {
        $called = 0;

        [$response, $output] = self::send(
            self::kernel($called, $parserMaxBytes),
            'POST',
            '/api/tasks',
            $body,
            ['CONTENT_TYPE' => $contentType],
        );

        self::assertSame(
            $expectedStatus,
            $response->status,
        );

        self::assertSame(
            $expectedStatus,
            $output->status(),
        );

        self::assertSame(1, $called);

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);

        self::assertSame(
            $expectedStatus,
            $problem['status'],
        );

        self::assertArrayNotHasKey(
            'errors',
            $problem,
        );
    }

    public function testValidFieldsProduce201AndPreserveOriginalTypes(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            'POST',
            '/api/tasks',
            '{"title":"مرحبا","priority":3,"active":false}',
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
                    'title' => 'مرحبا',
                    'priority' => 3,
                    'active' => false,
                ],
            ],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testOptionalFieldsStayAbsentFromValidatedPayload(): void
    {
        [$response] = self::send(
            self::kernel(),
            'POST',
            '/api/tasks',
            '{"title":"Task"}',
        );

        self::assertSame(201, $response->status);

        self::assertSame(
            ['data' => ['title' => 'Task']],
            json_decode(
                $response->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testInvalidRequestDoesNotPoisonNextValidRequest(): void
    {
        $called = 0;
        $kernel = self::kernel($called);

        [$invalid] = self::send(
            $kernel,
            'POST',
            '/api/tasks',
            '{}',
        );

        [$valid] = self::send(
            $kernel,
            'POST',
            '/api/tasks',
            '{"title":"Next"}',
        );

        self::assertSame(422, $invalid->status);
        self::assertSame(201, $valid->status);
        self::assertSame(2, $called);

        self::assertSame(
            ['data' => ['title' => 'Next']],
            json_decode(
                $valid->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testHeadValidationFailureEmitsNoBody(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            'HEAD',
            '/api/invalid',
        );

        self::assertSame(422, $response->status);
        self::assertSame(422, $output->status());

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        self::assertSame('', $response->body);
        self::assertSame('', $output->body());
    }

    public function testUnexpectedHandlerFailureStillReturnsSafe500(): void
    {
        [$response] = self::send(
            self::kernel(),
            'GET',
            '/api/crash',
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

    public function testMalformedIngressReturns400WithoutCallingHandler(): void
    {
        $called = 0;
        $kernel = self::kernel($called);

        [$response, $output] = self::send(
            $kernel,
            'POST',
            '/api/tasks',
            '{"title":"Task"}',
            ['CONTENT_LENGTH' => '1'],
        );

        self::assertSame(400, $response->status);
        self::assertSame(400, $output->status());
        self::assertSame(0, $called);

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );
    }

    public function testOversizedIngressReturns413WithoutCallingHandler(): void
    {
        $called = 0;

        [$response, $output] = self::send(
            self::kernel($called),
            'POST',
            '/api/tasks',
            '{"title":"Task"}',
            [],
            8,
        );

        self::assertSame(413, $response->status);
        self::assertSame(413, $output->status());
        self::assertSame(0, $called);
    }

    public function testUnknownRouteAndMethodRetainRouterContracts(): void
    {
        $kernel = self::kernel();

        [$missing] = self::send(
            $kernel,
            'GET',
            '/api/not-found',
        );

        [$method] = self::send(
            $kernel,
            'DELETE',
            '/api/tasks',
        );

        self::assertSame(404, $missing->status);
        self::assertSame(405, $method->status);

        self::assertNotSame(
            'application/problem+json',
            $missing->header('Content-Type'),
        );

        self::assertNotSame(
            'application/problem+json',
            $method->header('Content-Type'),
        );
    }

    public function testWithoutProblemMiddlewareValidationExceptionStillPropagates(): void
    {
        $kernel = self::kernel(
            withMiddleware: false,
        );

        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        try {
            $runner->runSafely(
                [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI' => '/api/tasks',
                    'CONTENT_TYPE' => 'application/json',
                ],
                '{}',
            );

            self::fail(
                'Expected the application validation exception.',
            );
        } catch (ValidationException $exception) {
            self::assertSame(
                ['title' => ['required']],
                $exception->errors(),
            );
        }

        self::assertNull($output->status());
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{Response, BufferedResponseOutput}
     */
    private static function send(
        Kernel $kernel,
        string $method,
        string $path,
        string $body = '',
        array $extra = [],
        int $maxBytes = 1048576,
    ): array {
        $server = array_replace(
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
                'CONTENT_TYPE' => 'application/json',
            ],
            $extra,
        );

        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory($maxBytes),
            $kernel,
            new ResponseEmitter($output),
        );

        return [
            $runner->runSafely($server, $body),
            $output,
        ];
    }

    private static function kernel(
        ?int &$called = null,
        int $parserMaxBytes = 1048576,
        bool $withMiddleware = true,
    ): Kernel {
        $kernel = new Kernel(
            new Application(),
        );

        if ($withMiddleware) {
            $kernel->middleware(
                new ProblemExceptionHandler(),
            );
        }

        $parser = new JsonBodyParser(
            $parserMaxBytes,
        );

        $validator = new Validator();

        $schema = [
            'title' => [
                'type' => 'string',
                'required' => true,
                'notBlank' => true,
                'minLength' => 2,
                'maxLength' => 10,
            ],
            'priority' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 10,
            ],
            'active' => [
                'type' => 'boolean',
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
                &$called,
            ): Response {
                if ($called !== null) {
                    ++$called;
                }

                $input = $parser->parseObject(
                    $request,
                );

                $result = $validator->validate(
                    $input,
                    $schema,
                );

                if (! $result->isValid()) {
                    throw new ValidationException(
                        $result,
                    );
                }

                return new Response(
                    201,
                    [
                        'Content-Type' => 'application/json',
                    ],
                    json_encode(
                        [
                            'data' => $result->validated(),
                        ],
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
                        ['title' => ['required']],
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
}
