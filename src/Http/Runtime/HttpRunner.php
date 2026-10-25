<?php

declare(strict_types=1);

namespace Careminate\Http\Runtime;

use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\ServerRequestFactory;
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

    public function hasExecuted(): bool
    {
        return $this->executed;
    }

    private function execute(Request $request): Response
    {
        $response = $this->kernel->handle($request);

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
                'HTTP runtime has already executed.'
            );
        }
    }
}
