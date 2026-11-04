<?php

declare(strict_types=1);

namespace Careminate\Console;

use Careminate\Http\Routing\Router;
use JsonException;

/**
 * Read-only listing of explicitly registered HTTP routes.
 *
 * Route metadata is checked before being rendered to the
 * terminal or included in a JSON document.
 */
final readonly class RouteListCommand
{
    public function __construct(
        private Router $router,
    ) {
    }

    /**
     * Validate CLI arguments without loading or inspecting routes.
     *
     * A null result means the arguments are valid.
     *
     * @param list<string> $arguments
     */
    public static function usageError(
        array $arguments,
    ): ?ConsoleResult {
        if (
            $arguments === []
            || $arguments === ['--format=table']
            || $arguments === ['--format=json']
        ) {
            return null;
        }

        return new ConsoleResult(
            ConsoleApplication::EXIT_USAGE,
            stderr: "Invalid route:list arguments.\n"
                . "Usage: php careminate route:list [--format=table|json]\n",
        );
    }

    /**
     * @param list<string> $arguments
     */
    public function __invoke(
        array $arguments,
    ): ConsoleResult {
        $usageError = self::usageError($arguments);

        if ($usageError !== null) {
            return $usageError;
        }

        $routes = $this->safeRoutes();

        if ($routes instanceof ConsoleResult) {
            return $routes;
        }

        return $arguments === ['--format=json']
            ? $this->json($routes)
            : $this->table($routes);
    }

    /**
     * Validate metadata that could be rendered to a terminal.
     *
     * Reject malformed UTF-8, control characters and
     * Unicode formatting characters (including bidi controls).
     *
     * @return list<array{
     *     method: string,
     *     path: string,
     *     name: ?string
     * }>|ConsoleResult
     */
    private function safeRoutes(): array|ConsoleResult
    {
        $routes = $this->router->registeredRoutes();

        foreach ($routes as $route) {
            foreach (
                [
                    $route['method'],
                    $route['path'],
                    $route['name'] ?? '',
                ] as $label
            ) {
                if (
                    preg_match('//u', $label) !== 1
                    || preg_match(
                        '/[\p{Cc}\p{Cf}]/u',
                        $label,
                    ) !== 0
                ) {
                    return $this->unsafeMetadata();
                }
            }
        }

        return $routes;
    }

    /**
     * @param list<array{
     *     method: string,
     *     path: string,
     *     name: ?string
     * }> $routes
     */
    private function table(
        array $routes,
    ): ConsoleResult {
        if ($routes === []) {
            return new ConsoleResult(
                0,
                "No registered routes.\n",
            );
        }

        $methodWidth = strlen('METHOD');
        $pathWidth = strlen('PATH');

        foreach ($routes as $route) {
            $methodWidth = max(
                $methodWidth,
                strlen($route['method']),
            );

            $pathWidth = max(
                $pathWidth,
                strlen($route['path']),
            );
        }

        $lines = [
            sprintf(
                '%-*s  %-*s  %s',
                $methodWidth,
                'METHOD',
                $pathWidth,
                'PATH',
                'NAME',
            ),
        ];

        foreach ($routes as $route) {
            $lines[] = sprintf(
                '%-*s  %-*s  %s',
                $methodWidth,
                $route['method'],
                $pathWidth,
                $route['path'],
                $route['name'] ?? '-',
            );
        }

        return new ConsoleResult(
            0,
            implode("\n", $lines) . "\n",
        );
    }

    /**
     * @param list<array{
     *     method: string,
     *     path: string,
     *     name: ?string
     * }> $routes
     */
    private function json(
        array $routes,
    ): ConsoleResult {
        try {
            $encoded = json_encode(
                $routes,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return $this->unsafeMetadata();
        }

        return new ConsoleResult(
            0,
            $encoded . "\n",
        );
    }

    private function unsafeMetadata(): ConsoleResult
    {
        return new ConsoleResult(
            70,
            stderr: "Cannot display route metadata safely.\n",
        );
    }
}
