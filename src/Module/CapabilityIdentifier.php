<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Module\Exception\InvalidModuleDefinitionException;

/**
 * A validated capability name, independent of module identity.
 *
 * @api
 */
final readonly class CapabilityIdentifier
{
    public string $value;

    public function __construct(string $value)
    {
        if (
            strlen($value) > 64
            || preg_match('/\A[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\z/', $value) !== 1
        ) {
            throw new InvalidModuleDefinitionException(
                'A capability identifier must contain 1 to 64 lowercase ASCII '
                . 'letters, digits, dots or hyphens, start with a letter, '
                . 'and contain non-empty alphanumeric segments.',
            );
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
