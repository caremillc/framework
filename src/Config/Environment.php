<?php

declare(strict_types=1);

namespace Careminate\Config;

use InvalidArgumentException;
use RuntimeException;

final class Environment
{
    /**
     * @var array<string, string>
     */
    private array $values;

    /**
     * @param array<string, string> $defaults
     * @param array<string, string> $file
     * @param array<string, string> $external
     * @param array<string, string> $overrides
     */
    public function __construct(
        array $defaults = [],
        array $file = [],
        array $external = [],
        array $overrides = []
    ) {
        $this->values = array_replace(
            $defaults,
            $file,
            $external,
            $overrides
        );
    }

    /**
     * @param array<string, string> $external
     * @param array<string, string> $defaults
     * @param array<string, string> $overrides
     */
    public static function fromFile(
        string $path,
        array $external = [],
        array $defaults = [],
        array $overrides = []
    ): self {
        if (!is_file($path)) {
            return new self($defaults, [], $external, $overrides);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read environment file.'
            );
        }

        return new self(
            $defaults,
            self::parse($content),
            $external,
            $overrides
        );
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match(
                '/^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/s',
                $line,
                $matches
            ) !== 1) {
                throw new InvalidArgumentException(
                    'Invalid environment assignment.'
                );
            }

            $key = $matches[1];
            $value = trim($matches[2]);

            if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                $quote = $value[0];

                if (
                    strlen($value) < 2
                    || !str_ends_with($value, $quote)
                ) {
                    throw new InvalidArgumentException(
                        'Unterminated quoted environment value.'
                    );
                }

                $value = substr($value, 1, -1);
            } elseif (
                str_contains($value, '#')
                || str_contains($value, '"')
                || str_contains($value, "'")
            ) {
                throw new InvalidArgumentException(
                    'Unsupported unquoted environment syntax.'
                );
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function required(string $key): string
    {
        if (!$this->has($key)) {
            throw new InvalidArgumentException(
                sprintf('Required environment key "%s" is missing.', $key)
            );
        }

        return $this->values[$key];
    }

    public function boolean(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return match (strtolower($this->values[$key])) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new InvalidArgumentException(
                sprintf('Environment key "%s" must be boolean.', $key)
            ),
        };
    }

    public function integer(string $key, ?int $default = null): int
    {
        if (!$this->has($key)) {
            if ($default !== null) {
                return $default;
            }

            throw new InvalidArgumentException(
                sprintf('Required integer environment key "%s" is missing.', $key)
            );
        }

        $value = filter_var(
            $this->values[$key],
            FILTER_VALIDATE_INT
        );

        if ($value === false) {
            throw new InvalidArgumentException(
                sprintf('Environment key "%s" must be an integer.', $key)
            );
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
