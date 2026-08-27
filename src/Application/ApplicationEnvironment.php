<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Exception\Application\InvalidEnvironmentException;

final readonly class ApplicationEnvironment
{
    private function __construct(
        private string $name,
        private bool $debug,
        private bool $production,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw InvalidEnvironmentException::invalidName($name);
        }

        if ($production && $debug) {
            throw InvalidEnvironmentException::productionDebugging();
        }
    }

    public static function local(bool $debug = true): self
    {
        return new self('local', $debug, false);
    }

    public static function testing(bool $debug = true): self
    {
        return new self('testing', $debug, false);
    }

    public static function staging(bool $debug = false): self
    {
        return new self('staging', $debug, false);
    }

    public static function production(): self
    {
        return new self('production', false, true);
    }

    public static function custom(
        string $name,
        bool $debug = false,
        bool $production = false,
    ): self {
        return new self($name, $debug, $production);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    public function productionMode(): bool
    {
        return $this->production;
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }
}
