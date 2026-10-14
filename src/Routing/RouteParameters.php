<?php

declare(strict_types=1);

namespace Flint\Routing;

use Flint\Http\Request;
use LogicException;

final readonly class RouteParameters
{
    /**
     * @param array<string, non-empty-string> $values
     */
    private function __construct(
        private array $values,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        if (! $request->hasAttribute('route.parameters')) {
            throw new LogicException(
                'The request has no route parameter context.',
            );
        }

        $values = $request->attribute('route.parameters');

        if (! is_array($values)) {
            throw new LogicException(
                'Route parameter context must be an array.',
            );
        }

        $validated = [];

        foreach ($values as $name => $value) {
            if (
                ! is_string($name)
                || $name === ''
                || ! is_string($value)
                || $value === ''
            ) {
                throw new LogicException(
                    'Route parameters must have non-empty string names and values.',
                );
            }

            $validated[$name] = $value;
        }

        return new self($validated);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): string
    {
        if (! array_key_exists($name, $this->values)) {
            throw new LogicException(
                sprintf('Required route parameter "%s" is missing.', $name),
            );
        }

        return $this->values[$name];
    }
}
