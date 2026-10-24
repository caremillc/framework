<?php

declare(strict_types=1);

namespace Careminate\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use InvalidArgumentException;

final readonly class Route
{
    public string $method;

    public string $path;

    /**
     * @var Closure(Request): Response
     */
    private Closure $handler;

    /**
     * @param Closure(Request): Response $handler
     */
    public function __construct(
        string $method,
        string $path,
        Closure $handler,
    ) {
        $normalizedMethod = strtoupper($method);

        if (
            preg_match(
                "/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D",
                $method
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid route method.'
            );
        }

        if (
            $path === ''
            || $path[0] !== '/'
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid route path.'
            );
        }

        $this->method = $normalizedMethod;
        $this->path = $path;
        $this->handler = $handler;
    }

    public function run(Request $request): Response
    {
        return ($this->handler)($request);
    }
}
