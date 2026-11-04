<?php

declare(strict_types=1);

namespace Careminate\Http\Runtime;

use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Exception\ProblemResponseFactory;
use Careminate\Http\Exception\RequestBodyTooLargeException;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\ServerRequestFactory;
use Closure;
use InvalidArgumentException;
use LogicException;

final class HttpRunner
{
    private bool $executed = false;

    public function __construct(
        private readonly ServerRequestFactory $requestFactory,
        private readonly Kernel $kernel,
        private readonly ResponseEmitter $emitter,
    ) {
    }

    /**
     * Existing behavior: input validation exceptions propagate.
     *
     * @param array<string, mixed> $server
     */
    public function run(
        array $server,
        string $body = '',
    ): Response {
        $this->assertNotExecuted();

        $this->executed = true;

        $request = $this->requestFactory->fromServer(
            $server,
            $body,
        );

        return $this->execute($request);
    }

    /**
     * @param array<string, mixed> $server
     */
    public function runFromGlobals(array $server): Response
    {
        $this->assertNotExecuted();

        $this->executed = true;

        $request = $this->requestFactory->fromGlobals(
            $server,
        );

        return $this->execute($request);
    }

    /**
     * Opt-in response mapping for inbound request validation failures.
     *
     * @param array<string, mixed> $server
     */
    public function runSafely(
        array $server,
        string $body = '',
    ): Response {
        return $this->executeSafely(
            fn (): Request =>
                $this->requestFactory->fromServer(
                    $server,
                    $body,
                ),
            $server['REQUEST_METHOD'] ?? null,
        );
    }

    /**
     * Opt-in response mapping for native HTTP requests.
     *
     * @param array<string, mixed> $server
     */
    public function runFromGlobalsSafely(
        array $server,
    ): Response {
        return $this->executeSafely(
            fn (): Request =>
                $this->requestFactory->fromGlobals(
                    $server,
                ),
            $server['REQUEST_METHOD'] ?? null,
        );
    }

    /**
     * @param Closure(): Request $makeRequest
     */
    private function executeSafely(
        Closure $makeRequest,
        mixed $method,
    ): Response {
        $this->assertNotExecuted();

        $this->executed = true;

        try {
            $request = $makeRequest();
        } catch (RequestBodyTooLargeException) {
            return $this->emitInputProblem(
                413,
                $method,
            );
        } catch (InvalidArgumentException) {
            return $this->emitInputProblem(
                400,
                $method,
            );
        }

        return $this->execute($request);
    }

    private function emitInputProblem(
        int $status,
        mixed $method,
    ): Response {
        $response = new ProblemResponseFactory()->create(
            $status,
        );

        // HEAD responses must never emit a body, even when
        // request validation failed before the kernel ran.
        if (
            is_string($method)
            && strtoupper($method) === 'HEAD'
        ) {
            $response = $response->withBody('');
        }

        $this->emitter->emit($response);

        return $response;
    }

    public function hasExecuted(): bool
    {
        return $this->executed;
    }

    private function execute(
        Request $request,
    ): Response {
        $response = $this->kernel->handle(
            $request,
        );

        if ($request->method === 'HEAD') {
            $response = $response->withBody('');
        }

        $this->emitter->emit($response);

        return $response;
    }

    private function assertNotExecuted(): void
    {
        if ($this->executed) {
            throw new LogicException(
                'HTTP runtime has already executed.',
            );
        }
    }
}
