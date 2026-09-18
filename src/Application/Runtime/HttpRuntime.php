<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Application\RuntimeInterface;
use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handles one HTTP request and passes its response to an emitter.
 *
 * @api
 */
final readonly class HttpRuntime implements RuntimeInterface
{
    /**
     * @param Closure(ResponseInterface): void $emit
     */
    public function __construct(
        private ServerRequestInterface $request,
        private RequestHandlerInterface $handler,
        private Closure $emit,
    ) {
    }

    public function run(ContainerInterface $container): int
    {
        $response = $this->handler->handle($this->request);

        ($this->emit)($response);

        return 0;
    }
}
