<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Module\Exception\InvalidModuleVersionException;

/**
 * @api
 */
final readonly class ModuleVersion
{
    private const int MAX_BYTES = 256;

    private const string PATTERN =
        '/\A(0|[1-9][0-9]*)'
        . '\.(0|[1-9][0-9]*)'
        . '\.(0|[1-9][0-9]*)'
        . '(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';

    /**
     * @var list<string>
     */
    private array $core;

    /**
     * @var list<string>|null
     */
    private ?array $prerelease;

    public function __construct(
        public string $value,
    ) {
        if (
            strlen($value) > self::MAX_BYTES
            || preg_match(self::PATTERN, $value, $matches) !== 1
        ) {
            throw new InvalidModuleVersionException(
                'A module version must be valid SemVer within 256 bytes.',
            );
        }

        $this->core = [
            $matches[1],
            $matches[2],
            $matches[3],
        ];

        $prerelease = $matches[4] ?? '';

        if ($prerelease === '') {
            $this->prerelease = null;

            return;
        }

        $identifiers = explode('.', $prerelease);

        foreach ($identifiers as $identifier) {
            if (
                self::isNumeric($identifier)
                && strlen($identifier) > 1
                && $identifier[0] === '0'
            ) {
                throw new InvalidModuleVersionException(
                    'Numeric prerelease identifiers cannot have leading zeroes.',
                );
            }
        }

        $this->prerelease = $identifiers;
    }

    /**
     * Compare precedence, ignoring build metadata.
     *
     * @return -1|0|1
     */
    public function compare(self $other): int
    {
        foreach ($this->core as $index => $component) {
            $comparison = self::compareNumeric(
                $component,
                $other->core[$index],
            );

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        if ($this->prerelease === null) {
            return $other->prerelease === null ? 0 : 1;
        }

        if ($other->prerelease === null) {
            return -1;
        }

        foreach ($this->prerelease as $index => $identifier) {
            $otherIdentifier = $other->prerelease[$index] ?? null;

            if ($otherIdentifier === null) {
                return 1;
            }

            if ($identifier === $otherIdentifier) {
                continue;
            }

            $numeric = self::isNumeric($identifier);
            $otherNumeric = self::isNumeric($otherIdentifier);

            if ($numeric && $otherNumeric) {
                return self::compareNumeric(
                    $identifier,
                    $otherIdentifier,
                );
            }

            if ($numeric !== $otherNumeric) {
                return $numeric ? -1 : 1;
            }

            return strcmp($identifier, $otherIdentifier) <=> 0;
        }

        return count($this->prerelease) <=> count($other->prerelease);
    }

    private static function isNumeric(string $identifier): bool
    {
        return preg_match('/\A[0-9]+\z/', $identifier) === 1;
    }

    /**
     * Compare validated decimal strings without integer conversion.
     *
     * @return -1|0|1
     */
    private static function compareNumeric(
        string $left,
        string $right,
    ): int {
        $lengthComparison = strlen($left) <=> strlen($right);

        if ($lengthComparison !== 0) {
            return $lengthComparison;
        }

        return strcmp($left, $right) <=> 0;
    }
}
