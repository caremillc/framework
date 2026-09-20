<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\ModuleIdentifier;
use JsonException;

/**
 * Deterministic identity for explicitly supplied module-cache inputs.
 *
 * @internal
 */
final readonly class ModuleCacheIdentity
{
    private const int SCHEMA_VERSION = 1;

    private string $fingerprint;

    /**
     * @param array<string, string> $sourceFingerprints
     * @param list<string> $selectedPackages
     * @param list<ModuleIdentifier> $disabled
     */
    public function __construct(
        string $buildId,
        array $sourceFingerprints,
        array $selectedPackages = [],
        array $disabled = [],
        string $phpVersion = PHP_VERSION,
    ) {
        self::validateLabel($buildId);
        self::validateLabel($phpVersion);

        $sources = [];

        foreach ($sourceFingerprints as $name => $digest) {
            self::validateLabel($name);

            if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                throw new ModuleCacheException(
                    'A module source fingerprint must be a lowercase SHA-256 digest.',
                );
            }

            $sources[] = [$name, $digest];
        }

        usort(
            $sources,
            static fn (array $first, array $second): int =>
                strcmp($first[0], $second[0]),
        );

        foreach ($selectedPackages as $package) {
            self::validateLabel($package);
        }

        $packages = array_unique($selectedPackages, SORT_STRING);
        sort($packages, SORT_STRING);

        $disabledNames = [];

        foreach ($disabled as $identifier) {
            $disabledNames[] = $identifier->value;
        }

        $disabledNames = array_unique($disabledNames, SORT_STRING);
        sort($disabledNames, SORT_STRING);

        try {
            $payload = json_encode(
                [
                    'schema' => self::SCHEMA_VERSION,
                    'build' => $buildId,
                    'php' => $phpVersion,
                    'sources' => $sources,
                    'packages' => $packages,
                    'disabled' => $disabledNames,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $previous) {
            throw new ModuleCacheException(
                message: 'Module cache identity inputs could not be encoded.',
                previous: $previous,
            );
        }

        $this->fingerprint = hash(
            'sha256',
            "careminate.module-cache.identity\0" . $payload,
        );
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    private static function validateLabel(string $value): void
    {
        if (
            $value === ''
            || strlen($value) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new ModuleCacheException(
                'Module cache identity labels must contain 1 to 1024 bytes '
                . 'without control characters.',
            );
        }
    }
}
