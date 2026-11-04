<?php

declare(strict_types=1);

namespace Careminate\Console;

use Careminate\Http\Routing\Router;
use Closure;

/**
 * Load application routes only for a valid route:list invocation.
 *
 * Route-loader exceptions propagate to the application's CLI
 * entrypoint, which owns the final exception boundary.
 */
final readonly class LazyRouteListCommand
{
    /**
     * @param Closure(): Router $loadRouter
     */
    public function __construct(
        private Closure $loadRouter,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function __invoke(
        array $arguments,
    ): ConsoleResult {
        $usageError = RouteListCommand::usageError(
            $arguments,
        );

        if ($usageError !== null) {
            return $usageError;
        }

        $router = ($this->loadRouter)();

        return (new RouteListCommand($router))(
            $arguments,
        );
    }
}
