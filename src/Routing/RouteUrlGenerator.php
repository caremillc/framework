<?php

declare(strict_types=1);

namespace Flint\Routing;

final readonly class RouteUrlGenerator
{
    public function __construct(
        private Router $router,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function path(
        string $name,
        array $parameters = [],
    ): string {
        return $this->router->path($name, $parameters);
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
        return $this->router->target(
            $name,
            $parameters,
            $query,
        );
    }
}
