<?php

declare(strict_types=1);

namespace Careminate\Config;

use InvalidArgumentException;

final class ConfigurationValidator
{
    /**
     * @param array<string, 'string'|'boolean'|'integer'|'array'|'non-empty-string'> $rules
     */
    public function validate(Configuration $configuration, array $rules): void
    {
        foreach ($rules as $key => $rule) {
            if ($key === '' || !$configuration->has($key)) {
                throw new InvalidArgumentException(
                    sprintf('Required configuration key "%s" is missing.', $key)
                );
            }

            $value = $configuration->required($key);

            $valid = match ($rule) {
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                'array' => is_array($value),
                'non-empty-string' => is_string($value) && trim($value) !== '',
            };

            if (!$valid) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configuration key "%s" failed validation rule "%s".',
                        $key,
                        $rule
                    )
                );
            }
        }
    }
}
