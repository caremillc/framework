<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Runtime;

use Careminate\Foundation\Application;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpRunnerTest extends TestCase
{
    public function testRunsRequestThroughKernelAndEmitter(): void
    {
        $output = new BufferedResponseOutput();
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    ['Content-Type' => 'text/plain'],
                    'healthy',
                ),
        );

        $runner = $this->createRunner($kernel, $output);

        $response = $runner->run([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
        ]);

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
        self::assertSame(200, $output->status());
        self::assertSame('healthy', $output->body());
        self::assertSame(
            'text/plain',
            $output->headers()['content-type'],
        );
    }

    public function testRunnerStartsUnused(): void
    {
        $runner = $this->createRunner(
            new Kernel(new Application()),
            new BufferedResponseOutput(),
        );

        self::assertFalse($runner->hasExecuted());
    }

    public function testSuccessfulExecutionMarksRunnerUsed(): void
    {
        $runner = $this->createRunner(
            new Kernel(new Application()),
            new BufferedResponseOutput(),
        );

        $runner->run($this->validServer());

        self::assertTrue($runner->hasExecuted());
    }

    public function testRejectsRepeatedExecution(): void
    {
        $runner = $this->createRunner(
            new Kernel(new Application()),
            new BufferedResponseOutput(),
        );

        $runner->run($this->validServer());

        $this->expectException(LogicException::class);

        $runner->run($this->validServer());
    }

    public function testInvalidRequestPreventsEmission(): void
    {
        $output = new BufferedResponseOutput();

        $runner = $this->createRunner(
            new Kernel(new Application()),
            $output,
        );

        try {
            $runner->run([
                'REQUEST_METHOD' => 'GET',
            ]);

            self::fail('Expected invalid request.');
        } catch (\InvalidArgumentException) {
            // Expected validation failure.
        }

        self::assertNull($output->status());
        self::assertSame('', $output->body());
        self::assertTrue($runner->hasExecuted());
    }

    public function testInvalidRequestCannotBeRetried(): void
    {
        $runner = $this->createRunner(
            new Kernel(new Application()),
            new BufferedResponseOutput(),
        );

        try {
            $runner->run([
                'REQUEST_METHOD' => 'GET',
            ]);
        } catch (\InvalidArgumentException) {
            // Expected validation failure.
        }

        $this->expectException(LogicException::class);

        $runner->run($this->validServer());
    }

    public function testKernelFailurePreventsEmission(): void
    {
        $output = new BufferedResponseOutput();
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Handler failed');
            },
        );

        $runner = $this->createRunner($kernel, $output);

        try {
            $runner->run([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/failure',
            ]);

            self::fail('Expected handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Handler failed',
                $exception->getMessage(),
            );
        }

        self::assertNull($output->status());
        self::assertTrue($runner->hasExecuted());
    }

    public function testKernelFailureCannotBeRetried(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Handler failed');
            },
        );

        $runner = $this->createRunner(
            $kernel,
            new BufferedResponseOutput(),
        );

        try {
            $runner->run([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/failure',
            ]);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->expectException(LogicException::class);

        $runner->run($this->validServer());
    }

    public function testEmitterFailurePropagates(): void
    {
        $output = new BufferedResponseOutput();

        // Pre-start output so emitter preflight rejects it.
        $output->sendStatus(200);

        $runner = $this->createRunner(
            new Kernel(new Application()),
            $output,
        );

        $this->expectException(LogicException::class);

        $runner->run($this->validServer());
    }

    public function testEmitterFailureConsumesRunner(): void
    {
        $output = new BufferedResponseOutput();
        $output->sendStatus(200);

        $runner = $this->createRunner(
            new Kernel(new Application()),
            $output,
        );

        try {
            $runner->run($this->validServer());
        } catch (LogicException) {
            // Expected output preflight failure.
        }

        self::assertTrue($runner->hasExecuted());
    }

    public function testEmitsNotFoundResponse(): void
    {
        $output = new BufferedResponseOutput();

        $runner = $this->createRunner(
            new Kernel(new Application()),
            $output,
        );

        $response = $runner->run($this->validServer());

        self::assertSame(404, $response->status);
        self::assertSame(404, $output->status());
        self::assertSame('Not Found', $output->body());
    }

    public function testPreservesRequestBody(): void
    {
        $output = new BufferedResponseOutput();
        $kernel = new Kernel(new Application());

        $kernel->router()->post(
            '/echo',
            static fn (Request $request): Response =>
                new Response(200, [], $request->body),
        );

        $runner = $this->createRunner($kernel, $output);

        $runner->run(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/echo',
            ],
            "\x00\x01\xFF",
        );

        self::assertSame(
            "\x00\x01\xFF",
            $output->body(),
        );
    }

    public function testPreservesQueryString(): void
    {
        $output = new BufferedResponseOutput();
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/search',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->queryString(),
                ),
        );

        $runner = $this->createRunner($kernel, $output);

        $runner->run([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/search?q=framework',
        ]);

        self::assertSame(
            'q=framework',
            $output->body(),
        );
    }

    public function testDoesNotAutomaticallyTerminateApplication(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $runner = $this->createRunner(
            $kernel,
            new BufferedResponseOutput(),
        );

        $runner->run($this->validServer());

        self::assertTrue($application->isBooted());
        self::assertFalse($application->isTerminated());
    }

    public function testKernelCanServeAnotherRunner(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $firstOutput = new BufferedResponseOutput();
        $secondOutput = new BufferedResponseOutput();

        $first = $this->createRunner(
            $kernel,
            $firstOutput,
        );

        $second = $this->createRunner(
            $kernel,
            $secondOutput,
        );

        $first->run($this->validServer());
        $second->run($this->validServer());

        self::assertSame(404, $firstOutput->status());
        self::assertSame(404, $secondOutput->status());
        self::assertTrue($application->isBooted());
    }

    public function testNestedRunnerExecutionIsRejected(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);
        $nestedRejected = false;

        $runner = $this->createRunner(
            $kernel,
            new BufferedResponseOutput(),
        );

        $kernel->router()->get(
            '/nested',
            static function (Request $request) use (
                $runner,
                &$nestedRejected,
            ): Response {
                try {
                    $runner->run([
                        'REQUEST_METHOD' => 'GET',
                        'REQUEST_URI' => '/nested',
                    ]);
                } catch (LogicException) {
                    $nestedRejected = true;
                }

                return new Response(200);
            },
        );

        $response = $runner->run([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/nested',
        ]);

        self::assertSame(200, $response->status);
        self::assertTrue($nestedRejected);
    }

    /**
     * @param Kernel $kernel
     */
    private function createRunner(
        Kernel $kernel,
        BufferedResponseOutput $output,
    ): HttpRunner {
        return new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validServer(): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/missing',
        ];
    }
}
