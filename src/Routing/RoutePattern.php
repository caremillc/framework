<?php

declare(strict_types=1);

namespace Flint\Routing;

use Flint\Http\Request;
use InvalidArgumentException;

final readonly class RoutePattern
{
    private string $regex;

    /** @var list<string> */
    private array $parameterNames;

    public function __construct(string $pattern)
    {
        $regexSegments = [];
        $validationSegments = [];
        $parameterNames = [];

        foreach (explode('/', $pattern) as $segment) {
            if (
                preg_match(
                    '/\A\{([A-Za-z_][A-Za-z0-9_]*)\}\z/',
                    $segment,
                    $matches,
                ) === 1
            ) {
                $name = $matches[1];

                if (in_array($name, $parameterNames, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Duplicate route parameter "%s".', $name),
                    );
                }

                $parameterNames[] = $name;
                $regexSegments[] = '([^/]+)';
                $validationSegments[] = 'parameter';

                continue;
            }

            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new InvalidArgumentException(
                    'Route parameters must occupy a complete path segment.',
                );
            }

            $regexSegments[] = preg_quote($segment, '~');
            $validationSegments[] = $segment;
        }

        // Reuse the existing origin-form target validation.
        $validation = new Request(
            target: implode('/', $validationSegments),
        );

        if ($validation->queryString() !== null) {
            throw new InvalidArgumentException(
                'Route patterns must not contain a query string.',
            );
        }

        $this->regex = '~\A' . implode('/', $regexSegments) . '\z~';
        $this->parameterNames = $parameterNames;
    }

    public function isLiteral(): bool
    {
        return $this->parameterNames === [];
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        $validation = new Request(target: $path);

        if ($validation->queryString() !== null) {
            throw new InvalidArgumentException(
                'Match a request path without its query string.',
            );
        }

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames as $index => $name) {
            $parameters[$name] = $matches[$index + 1];
        }

        return $parameters;
    }
}
