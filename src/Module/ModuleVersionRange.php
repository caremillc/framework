<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Module\Exception\InvalidModuleVersionRangeException;

/**
 * @api
 */
final readonly class ModuleVersionRange
{
    private function __construct(
        public ?ModuleVersion $minimum,
        public ?ModuleVersion $maximum,
        public bool $includeMinimum,
        public bool $includeMaximum,
    ) {
        if ($minimum === null || $maximum === null) {
            return;
        }

        $comparison = $minimum->compare($maximum);

        if (
            $comparison > 0
            || (
                $comparison === 0
                && (!$includeMinimum || !$includeMaximum)
            )
        ) {
            throw new InvalidModuleVersionRangeException(
                'A module version range must have ordered, nonempty bounds.',
            );
        }
    }

    public static function any(): self
    {
        return new self(null, null, false, false);
    }

    public static function exactly(ModuleVersion $version): self
    {
        return new self($version, $version, true, true);
    }

    public static function atLeast(
        ModuleVersion $minimum,
        bool $inclusive = true,
    ): self {
        return new self($minimum, null, $inclusive, false);
    }

    public static function atMost(
        ModuleVersion $maximum,
        bool $inclusive = true,
    ): self {
        return new self(null, $maximum, false, $inclusive);
    }

    public static function between(
        ModuleVersion $minimum,
        ModuleVersion $maximum,
        bool $includeMinimum = true,
        bool $includeMaximum = false,
    ): self {
        return new self(
            $minimum,
            $maximum,
            $includeMinimum,
            $includeMaximum,
        );
    }

    public function contains(ModuleVersion $version): bool
    {
        if ($this->minimum !== null) {
            $comparison = $version->compare($this->minimum);

            if (
                $comparison < 0
                || ($comparison === 0 && !$this->includeMinimum)
            ) {
                return false;
            }
        }

        if ($this->maximum !== null) {
            $comparison = $version->compare($this->maximum);

            if (
                $comparison > 0
                || ($comparison === 0 && !$this->includeMaximum)
            ) {
                return false;
            }
        }

        return true;
    }
}
