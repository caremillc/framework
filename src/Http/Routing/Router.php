<?php

declare(strict_types=1);

namespace Careminate\Http\Routing;

use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use InvalidArgumentException;
use LogicException;

final class Router
{
    /**
     * @var array<string, array<string, Route>>
     */
    private array $routes = [];

    /**
     * @var array<string, RoutePattern>
     */
    private array $patterns = [];

    private bool $frozen = false;

    /**
     * @var array<string, RoutePattern>
     */
    private array $namedRoutes = [];

    private string $groupPrefix = '';

    /**
     * @var list<Middleware>
     */
    private array $groupMiddleware = [];

    /**
     * @param Closure(Request): Response $handler
     * @param array<string, string> $constraints
     */
    public function add(
        string $method,
        string $path,
        Closure $handler,
        array $constraints = [],
        ?string $name = null,
    ): void {
        if ($this->frozen) {
            throw new LogicException(
                'Cannot register routes after the router is frozen.',
            );
        }

        $resolvedPath = $this->groupPrefix . $path;

        $route = new Route(
            $method,
            $resolvedPath,
            $handler,
            $this->groupMiddleware,
        );

        if (isset($this->routes[$route->path][$route->method])) {
            throw new LogicException(
                sprintf(
                    'Route "%s %s" is already registered.',
                    $route->method,
                    $route->path,
                ),
            );
        }

        if ($name !== null) {
            if (
                $name === ''
                || preg_match(
                    '/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D',
                    $name,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid route name.',
                );
            }

            if (isset($this->namedRoutes[$name])) {
                throw new LogicException(
                    sprintf(
                        'Route name "%s" is already registered.',
                        $name,
                    ),
                );
            }
        }

        $pattern = new RoutePattern(
            $route->path,
            $constraints,
        );

        if ($pattern->isDynamic()) {
            $existingPattern = $this->patterns[$route->path] ?? null;

            if (
                $existingPattern !== null
                && $existingPattern->constraints() !== $pattern->constraints()
            ) {
                throw new LogicException(
                    sprintf(
                        'Conflicting constraints for route pattern "%s".',
                        $route->path,
                    ),
                );
            }
        }

        // Commit registration only after all validation succeeds.
        $this->routes[$route->path][$route->method] = $route;

        if ($pattern->isDynamic()) {
            $this->patterns[$route->path] = $pattern;
        }

        if ($name !== null) {
            $this->namedRoutes[$name] = $pattern;
        }
    }

    /**
     * @param Closure(Request): Response $handler
     * @param array<string, string> $constraints
     */
    public function get(
        string $path,
        Closure $handler,
        array $constraints = [],
        ?string $name = null,
    ): void {
        $this->add(
            'GET',
            $path,
            $handler,
            $constraints,
            $name,
        );
    }

    /**
     * @param Closure(Request): Response $handler
     * @param array<string, string> $constraints
     */
    public function post(
        string $path,
        Closure $handler,
        array $constraints = [],
        ?string $name = null,
    ): void {
        $this->add(
            'POST',
            $path,
            $handler,
            $constraints,
            $name,
        );
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $allowed = [];

        // Exact routes take precedence over dynamic routes.
        if (isset($this->routes[$path])) {
            $exactRoutes = $this->routes[$path];

            if (isset($exactRoutes[$request->method])) {
                return $exactRoutes[$request->method]->run(
                    $request->withRouteParameters([]),
                );
            }

            foreach (array_keys($exactRoutes) as $method) {
                $allowed[$method] = true;
            }
        }

        /**
         * @var list<array{
         *     path: string,
         *     pattern: RoutePattern,
         *     parameters: array<string, string>,
         *     order: int
         * }> $matches
         */
        $matches = [];

        $order = 0;

        foreach ($this->patterns as $registeredPath => $pattern) {
            $parameters = $pattern->match($path);

            if ($parameters === null) {
                ++$order;

                continue;
            }

            $matches[] = [
                'path' => $registeredPath,
                'pattern' => $pattern,
                'parameters' => $parameters,
                'order' => $order,
            ];

            ++$order;
        }

        // More specific dynamic routes take precedence.
        usort(
            $matches,
            static function (array $left, array $right): int {
                $literalComparison =
                    $right['pattern']->literalSegmentCount()
                    <=> $left['pattern']->literalSegmentCount();

                if ($literalComparison !== 0) {
                    return $literalComparison;
                }

                $constraintComparison =
                    $right['pattern']->constrainedParameterCount()
                    <=> $left['pattern']->constrainedParameterCount();

                if ($constraintComparison !== 0) {
                    return $constraintComparison;
                }

                return $left['order'] <=> $right['order'];
            },
        );

        foreach ($matches as $match) {
            $dynamicRoutes = $this->routes[$match['path']];

            if (isset($dynamicRoutes[$request->method])) {
                return $dynamicRoutes[$request->method]->run(
                    $request->withRouteParameters(
                        $match['parameters'],
                    ),
                );
            }

            foreach (array_keys($dynamicRoutes) as $method) {
                $allowed[$method] = true;
            }
        }

        if ($allowed !== []) {
            $methods = array_keys($allowed);
            sort($methods, SORT_STRING);

            return new Response(
                405,
                [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Allow' => implode(', ', $methods),
                ],
                'Method Not Allowed',
            );
        }

        return new Response(
            404,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            'Not Found',
        );
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->routes as $routes) {
            $count += count($routes);
        }

        return $count;
    }

    public function hasName(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function url(
        string $name,
        array $parameters = [],
    ): string {
        $pattern = $this->namedRoutes[$name] ?? null;

        if ($pattern === null) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown route name "%s".',
                    $name,
                ),
            );
        }

        return $pattern->generate($parameters);
    }

    /**
     * @param Closure(self): void $callback
     * @param array<array-key, mixed> $middleware
     */
    public function group(
        string $prefix,
        Closure $callback,
        array $middleware = [],
    ): void {
        if ($this->frozen) {
            throw new LogicException(
                'Cannot register route groups after the router is frozen.',
            );
        }

        if (
            $prefix !== ''
            && (
                $prefix[0] !== '/'
                || (
                    $prefix !== '/'
                    && str_ends_with($prefix, '/')
                )
                || str_contains($prefix, '?')
                || str_contains($prefix, '#')
                || preg_match('/[\x00-\x20\x7F]/', $prefix) === 1
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid route group prefix.',
            );
        }

        $validatedMiddleware = [];

        foreach ($middleware as $item) {
            if (!$item instanceof Middleware) {
                throw new InvalidArgumentException(
                    'Route group middleware must implement Middleware.',
                );
            }

            $validatedMiddleware[] = $item;
        }

        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        if ($prefix !== '' && $prefix !== '/') {
            $this->groupPrefix .= $prefix;
        }

        $this->groupMiddleware = [
            ...$previousMiddleware,
            ...$validatedMiddleware,
        ];

        try {
            $callback($this);
        } finally {
            $this->groupPrefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }
}
