<?php

declare (strict_types=1);

namespace Flint\Routing;

use Closure;
use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\QueryStringEncoder;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

final class Router implements RequestHandler
{
    /**
     * @var array<string, array<string, Closure(Request): mixed>>
     */
    private array $literalRoutes = [];

    /** @var array<string, RoutePattern> */
    private array $namedRoutes = [];

    /**
     * @var list<array{
     *     pattern: RoutePattern,
     *     method: string,
     *     handler: Closure(Request): mixed
     * }>
     */
    private array $parameterRoutes = [];

    /** @var array<string, true> */
    private array $registrations = [];

    /**
     * @var list<array{
     *     method: string,
     *     path: string,
     *     name: string|null,
     *     constraints: array<string, string>
     * }>
     */
    private array $routeDescriptions = [];

    private bool $frozen = false;

    /**
     * @param callable(Request): mixed $handler
     * @param array<array-key, mixed> $constraints
     */
    public function add(
        string $method,
        string $path,
        callable $handler,
        ?string $name = null,
        array $constraints = [],
    ): void {
        $this->assertRegistrationOpen();
        new Request(method: $method);

        $pattern = new RoutePattern($path, $constraints);

        if ($name !== null) {
            if (
                preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/', $name) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Route names must start with a letter or underscore '
                    . 'and contain only letters, digits, underscores, dots, or hyphens.',
                );
            }

            if (isset($this->namedRoutes[$name])) {
                throw new InvalidArgumentException(
                    sprintf('Route name "%s" is already registered.', $name),
                );
            }
        }

        $shape = preg_replace(
            '/\{[A-Za-z_][A-Za-z0-9_]*\}/',
            '{}',
            $path,
        );

        if ($shape === null) {
            throw new LogicException('Could not normalize the route pattern.');
        }

        $registration = 'route:' . $method . ' ' . $shape;

        if (isset($this->registrations[$registration])) {
            throw new InvalidArgumentException(
                sprintf('Route "%s %s" is already registered.', $method, $path),
            );
        }

        // $callback = Closure::fromCallable($handler);
        $destination = Closure::fromCallable($handler);

        $callback = static function (Request $request) use (
            $destination,
            $name,
        ): mixed {
            $context = $request->attribute('route.log_context');

            if ($context instanceof RouteLogContext) {
                $context->select($name);
            }

            return $destination(
                $request->withAttribute('route.name', $name),
            );
        };

        if ($pattern->isLiteral()) {
            $this->literalRoutes[$path]['m:' . $method] = $callback;
        } else {
            $this->parameterRoutes[] = [
                'pattern' => $pattern,
                'method'  => $method,
                'handler' => $callback,
            ];
        }

        $this->registrations[$registration] = true;

        if ($name !== null) {
            $this->namedRoutes[$name] = $pattern;
        }

        $constraintDescriptions = [];

        foreach ($pattern->constraints() as $parameter => $constraint) {
            $constraintDescriptions[$parameter] = strtolower($constraint->name);
        }

        $this->routeDescriptions[] = [
            'method'      => $method,
            'path'        => $path,
            'name'        => $name,
            'constraints' => $constraintDescriptions,
        ];
    }

    public function handle(Request $request): Response
    {
        $literal = $this->literalRoutes[$request->path()] ?? null;

        if ($literal !== null) {
            return $this->handleLiteral($request, $literal);
        }

        $methods        = [];
        $headFallback   = null;
        $headParameters = [];

        foreach ($this->parameterRoutes as $route) {
            $parameters = $route['pattern']->match($request->path());

            if ($parameters === null) {
                continue;
            }

            $methods[] = $route['method'];

            if ($route['method'] === $request->method()) {
                return $this->invoke(
                    $route['handler'],
                    $request->withAttribute('route.parameters', $parameters),
                );
            }

            if (
                $request->method() === 'HEAD'
                && $route['method'] === 'GET'
                && $headFallback === null
            ) {
                $headFallback   = $route['handler'];
                $headParameters = $parameters;
            }
        }

        if ($headFallback !== null) {
            return $this->invoke(
                $headFallback,
                $request->withAttribute('route.parameters', $headParameters),
            );
        }

        if ($methods === []) {
            return ResponseFactory::text('Not found', 404);
        }

        return $this->methodNotAllowed($methods);
    }

    /**
     * @param array<string, Closure(Request): mixed> $handlers
     */
    private function handleLiteral(
        Request $request,
        array $handlers,
    ): Response {
        $handler = $handlers['m:' . $request->method()] ?? null;

        if ($handler !== null) {
            return $this->invoke($handler, $request);
        }

        if ($request->method() === 'HEAD') {
            $fallback = $handlers['m:GET'] ?? null;

            if ($fallback !== null) {
                return $this->invoke($fallback, $request);
            }
        }

        $methods = [];

        foreach (array_keys($handlers) as $key) {
            $methods[] = substr($key, 2);
        }

        return $this->methodNotAllowed($methods);
    }

    /**
     * @param list<string> $methods
     */
    private function methodNotAllowed(array $methods): Response
    {
        if (
            in_array('GET', $methods, true)
            && ! in_array('HEAD', $methods, true)
        ) {
            $methods[] = 'HEAD';
        }

        $methods = array_values(array_unique($methods));
        sort($methods, SORT_STRING);

        $response = ResponseFactory::text('Method not allowed', 405);

        return $response->withHeaders(
            $response->headers()->with('Allow', implode(', ', $methods)),
        );
    }

    /**
     * @param Closure(Request): mixed $handler
     */
    private function invoke(Closure $handler, Request $request): Response
    {
        $response = $handler($request);

        if (! $response instanceof Response) {
            throw new UnexpectedValueException(
                'Route handlers must return a Response.',
            );
        }

        return $response;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function path(string $name, array $parameters = []): string
    {
        $pattern = $this->namedRoutes[$name] ?? null;

        if ($pattern === null) {
            throw new InvalidArgumentException(
                sprintf('Unknown route name "%s".', $name),
            );
        }

        return $pattern->generate($parameters);
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @param array<array-key, mixed> $query
     */
    public function target(
        string $name,
        array $parameters = [],
        array $query = [],
    ): string {
        $path        = $this->path($name, $parameters);
        $queryString = QueryStringEncoder::encode($query);

        if ($queryString === '') {
            return $path;
        }

        return $path . '?' . $queryString;
    }

    public function addHandler(
        string $method,
        string $path,
        RequestHandler $handler,
        ?string $name = null,
        Middleware ...$middleware,
    ): void {
        $pipeline = new MiddlewarePipeline($handler, ...$middleware);

        $this->add(
            $method,
            $path,
            $pipeline->handle(...),
            $name,
        );
    }

    /**
     * @param array<array-key, mixed> $constraints
     */
    public function addConstrainedHandler(
        string $method,
        string $path,
        RequestHandler $handler,
        array $constraints,
        ?string $name = null,
        Middleware ...$middleware,
    ): void {
        $pipeline = new MiddlewarePipeline($handler, ...$middleware);

        $this->add(
            $method,
            $path,
            $pipeline->handle(...),
            $name,
            $constraints,
        );
    }
    public function group(
        string $prefix = '',
        string $namePrefix = '',
        Middleware ...$middleware,
    ): RouteGroup {
        return new RouteGroup(
            $this,
            $prefix,
            $namePrefix,
            ...$middleware,
        );
    }

    /**
     * @return list<array{
     *     method: string,
     *     path: string,
     *     name: string|null,
     *     constraints: array<string, string>
     * }>
     */
    public function describe(): array
    {
        return $this->routeDescriptions;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function assertRegistrationOpen(): void
    {
        if ($this->frozen) {
            throw new \LogicException(
                'Routes cannot be registered after the router is frozen.',
            );
        }
    }

}
