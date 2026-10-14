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

    /** @var array<string, RouteConstraint> */
    private array $constraints;

    private string $template;

    /**
     * @param array<array-key, mixed> $constraints
     */
    public function __construct(
        string $pattern,
        array $constraints = [],
    ) {
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

        $validation = new Request(
            target: implode('/', $validationSegments),
        );

        if ($validation->queryString() !== null) {
            throw new InvalidArgumentException(
                'Route patterns must not contain a query string.',
            );
        }

        $validatedConstraints = [];

        foreach ($constraints as $name => $constraint) {
            if (
                !is_string($name)
                || !in_array($name, $parameterNames, true)
            ) {
                throw new InvalidArgumentException(
                    'Constraints must refer to an existing route parameter.',
                );
            }

            if (!$constraint instanceof RouteConstraint) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Constraint for "%s" must be a RouteConstraint.',
                        $name,
                    ),
                );
            }

            $validatedConstraints[$name] = $constraint;
        }

        $this->regex = '~\A' . implode('/', $regexSegments) . '\z~';
        $this->parameterNames = $parameterNames;
        $this->constraints = $validatedConstraints;
        $this->template = $pattern;
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
            $value = $matches[$index + 1];
            $constraint = $this->constraints[$name] ?? null;

            if ($constraint !== null && !$constraint->matches($value)) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function generate(array $parameters = []): string
    {
        $replacements = [];

        foreach ($this->parameterNames as $name) {
            if (!array_key_exists($name, $parameters)) {
                throw new InvalidArgumentException(
                    sprintf('Missing route parameter "%s".', $name),
                );
            }

            $value = $parameters[$name];

            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(
                    sprintf(
                        'Route parameter "%s" must contain a non-empty string.',
                        $name,
                    ),
                );
            }

            if ($value === '.' || $value === '..') {
                throw new InvalidArgumentException(
                    sprintf(
                        'Route parameter "%s" must not be a dot segment.',
                        $name,
                    ),
                );
            }

            $constraint = $this->constraints[$name] ?? null;

            if ($constraint !== null && !$constraint->matches($value)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Route parameter "%s" does not satisfy its constraint.',
                        $name,
                    ),
                );
            }

            $replacements['{' . $name . '}'] = rawurlencode($value);
        }

        if (count($parameters) !== count($this->parameterNames)) {
            throw new InvalidArgumentException(
                'Unexpected route parameters were supplied.',
            );
        }

        return strtr($this->template, $replacements);
    }
}
