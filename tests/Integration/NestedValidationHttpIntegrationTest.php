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
use Careminate\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class NestedValidationHttpIntegrationTest extends TestCase
{
    public function testValidNestedPayloadReturnsFiltered201Response(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            '{"profile":{"name":"Ava"},"tasks":[{"title":"Build"},{"title":"Test"}]}',
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
                    'profile' => ['name' => 'Ava'],
                    'tasks' => [
                        ['title' => 'Build'],
                        ['title' => 'Test'],
                    ],
                ],
            ],
            json_decode(
                $response->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testMissingArrayMemberFieldReturnsIndexed422Error(): void
    {
        [$response, $output] = self::send(
            self::kernel(),
            '{"profile":{"name":"Ava"},"tasks":[{"title":"Build"},{}]}',
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

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);
        self::assertSame(422, $problem['status']);

        self::assertSame(
            ['tasks[1].title' => ['required']],
            $problem['errors'],
        );
    }

    public function testNestedUnknownFieldReturnsMarkerWithoutUserData(): void
    {
        [$response] = self::send(
            self::kernel(),
            '{"profile":{"name":"Ava"},"tasks":[{"title":"Build","private_token":"SECRET"}]}',
        );

        self::assertSame(422, $response->status);

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);

        self::assertSame(
            ['tasks[0]._input' => ['unknown_fields']],
            $problem['errors'],
        );

        self::assertStringNotContainsString(
            'private_token',
            $response->body,
        );

        self::assertStringNotContainsString(
            'SECRET',
            $response->body,
        );
    }

    public function testArraySizeFailureReturnsDocumented422Code(): void
    {
        [$response] = self::send(
            self::kernel(),
            '{"profile":{"name":"Ava"},"tasks":[]}',
        );

        self::assertSame(422, $response->status);

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);

        self::assertSame(
            ['tasks' => ['min_items']],
            $problem['errors'],
        );
    }

    public function testInvalidJsonStillReturns400WithoutValidationErrors(): void
    {
        [$response] = self::send(
            self::kernel(),
            '{"tasks":]',
        );

        self::assertSame(400, $response->status);

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);
        self::assertSame(400, $problem['status']);

        self::assertArrayNotHasKey(
            'errors',
            $problem,
        );
    }

    public function testRejectedRequestDoesNotPoisonLaterValidRequest(): void
    {
        $kernel = self::kernel();

        [$invalid] = self::send(
            $kernel,
            '{"profile":{},"tasks":[]}',
        );

        [$valid] = self::send(
            $kernel,
            '{"profile":{"name":"Next"},"tasks":[{"title":"Ready"}]}',
        );

        self::assertSame(422, $invalid->status);
        self::assertSame(201, $valid->status);

        self::assertSame(
            [
                'data' => [
                    'profile' => ['name' => 'Next'],
                    'tasks' => [
                        ['title' => 'Ready'],
                    ],
                ],
            ],
            json_decode(
                $valid->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
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
            'profile' => [
                'type' => 'object',
                'required' => true,
                'fields' => [
                    'name' => [
                        'type' => 'string',
                        'required' => true,
                        'notBlank' => true,
                    ],
                ],
            ],
            'tasks' => [
                'type' => 'array',
                'required' => true,
                'minItems' => 1,
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'fields' => [
                        'title' => [
                            'type' => 'string',
                            'required' => true,
                            'notBlank' => true,
                        ],
                    ],
                ],
            ],
        ];

        $kernel->router()->post(
            '/api/projects',
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
                        JSON_THROW_ON_ERROR
                            | JSON_UNESCAPED_UNICODE,
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
    ): array {
        $output = new BufferedResponseOutput();

        // One new runner per request; reuse the kernel only
        // when explicitly testing repeated-request isolation.
        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        $response = $runner->runSafely(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/api/projects',
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($body),
            ],
            $body,
        );

        return [$response, $output];
    }
}
