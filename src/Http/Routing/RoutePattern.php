<?php

declare(strict_types=1);

namespace Careminate\Http\Routing;

use InvalidArgumentException;

final class RoutePattern
{
    private readonly string $regex;

    /**
     * @var list<string>
     */
    private readonly array $parameterNames;

    private readonly int $literalSegments;

    private readonly int $constrainedParameters;

    /**
     * @var array<string, string>
     */
    private readonly array $constraints;

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

                    self::validateConstraint(
                        $name,
                        $constraint,
                    );

                    /*
                     * Named captures avoid positional-index errors.
                     *
                     * The custom constraint is grouped so that
                     * alternation remains inside this parameter.
                     *
                     * Captured values are subsequently checked
                     * for empty strings and path separators.
                     */
                    $compiled[] = '(?P<' . $name . '>[^/]+)';

                    ++$constrainedParameters;
                } else {
                    $compiled[] = '(?P<' . $name . '>[^/]+)';
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

        ksort($constraints, SORT_STRING);

        $this->constraints = $constraints;
    }

    private static function validateConstraint(
        string $name,
        string $constraint,
    ): void {
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

        $validationRegex = '~^(?:' . $constraint . ')$~D';

        if (@preg_match($validationRegex, '') === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid constraint expression for "%s".',
                    $name,
                ),
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function constraints(): array
    {
        return $this->constraints;
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

    private function isValidParameterValue(
        string $name,
        string $value,
    ): bool {
        if (
            $value === ''
            || str_contains($value, '/')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('//u', $value) !== 1
        ) {
            return false;
        }

        if (!isset($this->constraints[$name])) {
            return true;
        }

        return preg_match(
            '~^(?:' . $this->constraints[$name] . ')$~D',
            $value,
        ) === 1;
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

        foreach ($this->parameterNames as $name) {
            $encoded = $matches[$name] ?? null;

            if (!is_string($encoded) || $encoded === '') {
                return null;
            }

            if (
                preg_match(
                    '/%(?![0-9A-Fa-f]{2})/',
                    $encoded,
                ) === 1
            ) {
                return null;
            }

            $value = rawurldecode($encoded);

            if (!$this->isValidParameterValue($name, $value)) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }



    /**
    * @return list<string>
    */
    public function parameterNames(): array
    {
        return $this->parameterNames;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function generate(array $parameters = []): string
    {
        foreach ($this->parameterNames as $name) {
            if (!array_key_exists($name, $parameters)) {
                throw new InvalidArgumentException(
                    sprintf('Missing route parameter "%s".', $name),
                );
            }

            $value = (string) $parameters[$name];

            if (!$this->isValidParameterValue($name, $value)) {
                if (
                    isset($this->constraints[$name])
                    && $value !== ''
                    && !str_contains($value, '/')
                    && !str_contains($value, '\\')
                    && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
                    && preg_match('//u', $value) === 1
                ) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Route parameter "%s" violates its constraint.',
                            $name,
                        ),
                    );
                }

                throw new InvalidArgumentException(
                    sprintf('Invalid route parameter "%s".', $name),
                );
            }
        }

        foreach (array_keys($parameters) as $name) {
            if (!in_array($name, $this->parameterNames, true)) {
                throw new InvalidArgumentException(
                    sprintf('Unknown route parameter "%s".', $name),
                );
            }
        }

        $segments = explode('/', $this->pattern);

        foreach ($segments as &$segment) {
            if (
                preg_match(
                    '/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/D',
                    $segment,
                    $matches,
                ) !== 1
            ) {
                continue;
            }

            $segment = rawurlencode(
                (string) $parameters[$matches[1]],
            );
        }

        unset($segment);

        return implode('/', $segments);
    }

}
