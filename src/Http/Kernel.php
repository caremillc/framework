<?php

declare (strict_types=1);

namespace Careminate\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Middleware\MiddlewarePipeline;
use Careminate\Http\Middleware\Pipeline;
use Careminate\Http\Routing\Router;
use Closure;
use LogicException;

final class Kernel
{
    private bool $handling    = false;
    private bool $dispatching = false;
    private readonly Router $router;

    private readonly Pipeline $pipeline;

    private ?MiddlewarePipeline $middlewarePipeline = null;

    public function __construct(
        private readonly Application $application,
        ?Router $router = null,
        ?Pipeline $pipeline = null,
    ) {
        $this->router   = $router ?? new Router();
        $this->pipeline = $pipeline ?? new Pipeline();
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Register existing object-based middleware.
     */
    public function middleware(Middleware $middleware): void
    {
        $this->assertConfigurable();

        $this->pipeline->add($middleware);
    }

    /**
     * Register closure-based middleware.
     *
     * @param Closure(Request, Closure): Response $middleware
     */
    public function closureMiddleware(Closure $middleware): void
    {
        $this->assertConfigurable();

        $this->middlewarePipeline ??= new MiddlewarePipeline();

        $this->middlewarePipeline->add($middleware);
    }

    public function handle(Request $request): Response
    {
        if ($this->handling) {
            throw new LogicException(
                'Cannot handle a nested HTTP request.',
            );
        }

        if (
            $this->application->isTerminated()
            || $this->application->hasFailed()
        ) {
            throw new LogicException(
                'Cannot handle HTTP requests after application failure or termination.',
            );
        }

        $this->handling = true;

        try {
            $this->application->boot();

            $this->router->freeze();
            $this->pipeline->freeze();

            $this->dispatching = true;

            $terminal = fn (Request $request): Response =>
            $this->pipeline->dispatch(
                $request,
                $this->router,
            );

            if ($this->middlewarePipeline === null) {
                return $terminal($request);
            }

            return $this->middlewarePipeline->handle(
                $request,
                $terminal,
            );
        } finally {
            $this->dispatching = false;
            $this->handling    = false;
        }
    }

    public function terminate(): void
    {
        if ($this->handling) {
            throw new LogicException(
                'Cannot terminate the HTTP kernel during request handling.',
            );
        }

        $this->application->terminate();
    }

    private function assertConfigurable(): void
    {
        if (
            $this->dispatching
            || $this->application->isBooted()
            || $this->application->isTerminated()
            || $this->application->hasFailed()
            || $this->pipeline->isFrozen()
        ) {
            throw new LogicException(
                'HTTP middleware must be registered before application boot.',
            );
        }
    }

}
