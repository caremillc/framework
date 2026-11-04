<?php

declare (strict_types=1);

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
     * Named routes used for URL generation.
     *
     * @var array<string, RoutePattern>
     */
    private array $namedRoutes = [];

    private string $groupPrefix = '';
    /**
     * @var list<Middleware>
     */
    private array $groupMiddleware = [];

    /**
     * Route names for read-only route:list inspection.
     *
     * @var array<string, array<string, string>>
     */
    private array $routeNamesByPath = [];

    // /**
    //  * @param Closure(Request): Response $handler
    //  * @param array<string, string> $constraints
    //  */

    /**
     * Register an HTTP route.
     *
     * All validation is completed before changing router state.
     *
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

        // Reject duplicate method/path registrations.
        if (isset($this->routes[$route->path][$route->method])) {
            throw new LogicException(
                sprintf(
                    'Route "%s %s" is already registered.',
                    $route->method,
                    $route->path,
                ),
            );
        }

        // Validate names without modifying the registry yet.
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

        // Create the RoutePattern before using it anywhere.
        $pattern = new RoutePattern(
            $route->path,
            $constraints,
        );

        // Dynamic routes sharing a path must use compatible constraints.
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

        /*
     * All validation has completed successfully.
     * Router state can now be modified safely.
     */

        $this->routes[$route->path][$route->method] = $route;

        if ($pattern->isDynamic()) {
            $this->patterns[$route->path] = $pattern;
        }

        if ($name !== null) {
            // Existing named-route URL generation contract.
            // This property stores RoutePattern objects.
            $this->namedRoutes[$name] = $pattern;

            // New method-specific metadata for route:list.
            // This property stores route names as strings.
            $this->routeNamesByPath[$route->path][$route->method] = $name;
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
        $this->add('GET', $path, $handler, $constraints, $name);
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
        $this->add('POST', $path, $handler, $constraints, $name);
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
        $path    = $request->path();
        $allowed = [];

        $exactRoutes = $this->routes[$path] ?? [];

        foreach (array_keys($exactRoutes) as $method) {
            $allowed[$method] = true;
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
        $order   = 0;

        foreach ($this->patterns as $registeredPath => $pattern) {
            $parameters = $pattern->match($path);

            if ($parameters !== null) {
                $matches[] = [
                    'path'       => $registeredPath,
                    'pattern'    => $pattern,
                    'parameters' => $parameters,
                    'order'      => $order,
                ];

                foreach (
                    array_keys($this->routes[$registeredPath]) as $method
                ) {
                    $allowed[$method] = true;
                }
            }

            ++$order;
        }

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

        // Explicit HEAD routes precede GET fallback.
        $candidateMethods = $request->method === 'HEAD'
            ? ['HEAD', 'GET']
            : [$request->method];

        foreach ($candidateMethods as $method) {
            if (isset($exactRoutes[$method])) {
                return $exactRoutes[$method]->run(
                    $request->withRouteParameters([]),
                );
            }

            foreach ($matches as $match) {
                $routes = $this->routes[$match['path']];

                if (! isset($routes[$method])) {
                    continue;
                }

                return $routes[$method]->run(
                    $request->withRouteParameters(
                        $match['parameters'],
                    ),
                );
            }
        }

        if ($allowed !== []) {
            // HEAD is implicitly available for GET routes.
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }

            // Automatic OPTIONS is available for matched paths.
            $allowed['OPTIONS'] = true;

            $methods = array_keys($allowed);
            sort($methods, SORT_STRING);

            $allowHeader = implode(', ', $methods);

            if ($request->method === 'OPTIONS') {
                return new Response(
                    204,
                    ['Allow' => $allowHeader],
                );
            }

            return new Response(
                405,
                [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Allow'        => $allowHeader,
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

    /**
     * Return an independent, deterministic snapshot of registered routes.
     *
     * Includes only explicitly registered methods. Automatic HEAD or
     * OPTIONS behavior is not represented as a separate registration.
     *
     * Does not freeze, boot, dispatch or execute route handlers.
     *
     * @return list<array{
     *     method: string,
     *     path: string,
     *     name: ?string
     * }>
     */
    public function registeredRoutes(): array
    {
        $rows = [];

        foreach ($this->routes as $path => $methods) {
            foreach ($methods as $method => $route) {
                $rows[] = [
                    'method' => $route->method,
                    'path'   => $route->path,
                    'name'   => $this->routeNamesByPath[$path][$method] ?? null,
                ];
            }
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $byPath = strcmp(
                    $left['path'],
                    $right['path'],
                );

                return $byPath !== 0
                    ? $byPath
                    : strcmp(
                        $left['method'],
                        $right['method'],
                    );
            },
        );

        return $rows;
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
                sprintf('Unknown route name "%s".', $name),
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
            if (! $item instanceof Middleware) {
                throw new InvalidArgumentException(
                    'Route group middleware must implement Middleware.',
                );
            }

            $validatedMiddleware[] = $item;
        }

        $previousPrefix     = $this->groupPrefix;
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
            $this->groupPrefix     = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

}
