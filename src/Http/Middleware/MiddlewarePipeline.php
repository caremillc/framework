<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;

/**
 * Executes middleware around a terminal request handler.
 *
 * Middleware signature:
 * Closure(Request, Closure): Response
 *
 * Terminal handler signature:
 * Closure(Request): Response
 */
final class MiddlewarePipeline
{
    /**
     * @var list<Closure(Request, Closure): Response>
     */
    private array $middleware = [];

    /**
     * @param Closure(Request, Closure): Response $middleware
     */
    public function add(Closure $middleware): void
    {
        $this->middleware[] = $middleware;
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
                return $middleware($request, $downstream);
            };
        }

        return $next($request);
    }

    public function count(): int
    {
        return count($this->middleware);
    }
}
