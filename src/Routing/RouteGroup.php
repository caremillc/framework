<?php

declare(strict_types=1);

namespace Flint\Routing;

use Flint\Http\Middleware;
use Flint\Http\RequestHandler;
use InvalidArgumentException;

final readonly class RouteGroup
{
    /**
     * @var list<Middleware>
     */
    private array $middleware;

    public function __construct(
        private Router $router,
        private string $prefix = '',
        private string $namePrefix = '',
        Middleware ...$middleware,
    ) {
        self::validatePrefix($prefix);
        self::validateNamePrefix($namePrefix);

        $this->middleware = array_values($middleware);
    }

    public function group(
        string $prefix = '',
        string $namePrefix = '',
        Middleware ...$middleware,
    ): self {
        self::validatePrefix($prefix);
        self::validateNamePrefix($namePrefix);

        return new self(
            $this->router,
            $this->prefix . $prefix,
            $this->namePrefix . $namePrefix,
            ...array_merge(
                $this->middleware,
                array_values($middleware),
            ),
        );
    }

    public function addHandler(
        string $method,
        string $path,
        RequestHandler $handler,
        ?string $name = null,
        Middleware ...$middleware,
    ): void {
        // Validate the local path before adding the group prefix.
        new RoutePattern($path);

        if ($name !== null) {
            if ($name === '') {
                throw new InvalidArgumentException(
                    'A route name must not be empty.',
                );
            }

            self::validateNamePrefix($name);
        }

        $this->router->addHandler(
            $method,
            $this->prefix . $path,
            $handler,
            $name === null ? null : $this->namePrefix . $name,
            ...array_merge(
                $this->middleware,
                array_values($middleware),
            ),
        );
    }

    private static function validatePrefix(string $prefix): void
    {
        if ($prefix === '') {
            return;
        }

        if (str_ends_with($prefix, '/')) {
            throw new InvalidArgumentException(
                'A route group prefix must not end with a slash. '
                    . 'Use an empty prefix for the application root.',
            );
        }

        new RoutePattern($prefix);
    }

    private static function validateNamePrefix(string $prefix): void
    {
        if ($prefix === '') {
            return;
        }

        if (
            preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/', $prefix) !== 1
        ) {
            throw new InvalidArgumentException(
                'Route name prefixes must start with a letter or underscore '
                    . 'and contain only letters, digits, underscores, dots, or hyphens.',
            );
        }
    }
}
