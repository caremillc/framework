<?php

declare(strict_types=1);

namespace Careminate\Http\Routing;

use InvalidArgumentException;

final class RoutePattern
{
    private readonly string $regex;

    /** @var list<string> */
    private readonly array $parameterNames;

    private readonly int $literalSegments;

    private readonly int $constrainedParameters;

    /**
     * @param array<string, string> $constraints
     */
    public function __construct(
        public readonly string $pattern,
        array $constraints = [],
    ) {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new InvalidArgumentException(
                'Route pattern must begin with "/".',
            );
        }

        $segments = $pattern === '/'
            ? []
            : explode('/', substr($pattern, 1));

        $compiled = [];
        $names = [];
        $literalSegments = 0;
        $constrainedParameters = 0;

        foreach ($segments as $segment) {
            if (
                preg_match(
                    '/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D',
                    $segment,
                    $matches,
                ) === 1
            ) {
                $name = $matches[1];

                if (in_array($name, $names, true)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Duplicate route parameter "%s".',
                            $name,
                        ),
                    );
                }

                $names[] = $name;

                if (isset($constraints[$name])) {
                    $constraint = $constraints[$name];

                    if (
                        $constraint === ''
                        || str_contains($constraint, '/')
                        || str_contains($constraint, '~')
                        || str_contains($constraint, '(')
                        || str_contains($constraint, ')')
                    ) {
                        throw new InvalidArgumentException(
                            sprintf(
                                'Invalid constraint for "%s".',
                                $name,
                            ),
                        );
                    }

                    $compiled[] = '(' . $constraint . ')';
                    ++$constrainedParameters;
                } else {
                    $compiled[] = '([^/]+)';
                }

                continue;
            }

            if (
                str_contains($segment, '{')
                || str_contains($segment, '}')
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid route segment "%s".',
                        $segment,
                    ),
                );
            }

            $compiled[] = preg_quote($segment, '~');
            ++$literalSegments;
        }

        foreach (array_keys($constraints) as $name) {
            if (!in_array($name, $names, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Constraint references unknown parameter "%s".',
                        $name,
                    ),
                );
            }
        }

        $regex = '~^/' . implode('/', $compiled) . '$~D';

        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException(
                'Invalid route constraint expression.',
            );
        }

        $this->regex = $regex;
        $this->parameterNames = $names;
        $this->literalSegments = $literalSegments;
        $this->constrainedParameters = $constrainedParameters;
    }

    public function isDynamic(): bool
    {
        return $this->parameterNames !== [];
    }

    public function literalSegmentCount(): int
    {
        return $this->literalSegments;
    }

    public function constrainedParameterCount(): int
    {
        return $this->constrainedParameters;
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
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
