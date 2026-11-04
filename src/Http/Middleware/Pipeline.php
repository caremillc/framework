<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use LogicException;

final class Pipeline
{
    /**
     * @var list<Middleware>
     */
    private array $middleware = [];

    private bool $frozen = false;

    public function add(Middleware $middleware): void
    {
        if ($this->frozen) {
            throw new LogicException(
                'Cannot register middleware after the pipeline is frozen.'
            );
        }

        $this->middleware[] = $middleware;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @param Closure(Request): Response $handler
     */
    public function handle(
        Request $request,
        Closure $handler,
    ): Response {
        $next = $handler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $downstream = $next;

            $next = static function (
                Request $request,
            ) use ($middleware, $downstream): Response {
                return $middleware->process(
                    $request,
                    $downstream,
                );
            };
        }

        return $next($request);
    }

    public function dispatch(
        Request $request,
        Router $router,
    ): Response {
        return $this->handle(
            $request,
            static fn (Request $request): Response =>
                $router->dispatch($request),
        );
    }

    public function count(): int
    {
        return count($this->middleware);
    }
}
