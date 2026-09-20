<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\CapabilityIdentifier;
use Careminate\Module\CapabilityProvision;
use Careminate\Module\Exception\CapabilityResolutionException;
use Careminate\Module\Exception\InvalidModuleDefinitionException;
use Careminate\Module\Exception\InvalidModuleVersionException;
use Careminate\Module\Exception\InvalidModuleVersionRangeException;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleDependencyResolver;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleVersion;
use Careminate\Module\ModuleVersionRange;
use JsonException;
use stdClass;

/**
 * Serializes enabled module metadata and revalidates its relationships.
 *
 * @internal
 */
final class ModuleMetadataCodec
{
    private const int MAX_BYTES = 1048576;

    /**
     * @param list<ModuleDefinition> $modules
     */
    public function encode(array $modules): string
    {
        $ordered = $this->validate($modules);
        $schema = 1;

        foreach ($ordered as $module) {
            if (
                $module->version !== null
                || $module->dependencyVersions !== []
            ) {
                $schema = 2;

                break;
            }
        }

        $records = [];

        foreach ($ordered as $module) {
            $provisions = $module->providedCapabilities;

            usort(
                $provisions,
                static fn (
                    CapabilityProvision $first,
                    CapabilityProvision $second,
                ): int => strcmp(
                    $first->capability->value,
                    $second->capability->value,
                ),
            );

            $provided = [];

            foreach ($provisions as $provision) {
                $provided[] = [
                    'name' => $provision->capability->value,
                    'exclusive' => $provision->exclusive,
                ];
            }

            $record = [
                'id' => $module->id->value,
                'required' => $this->names($module->required),
                'optional' => $this->names($module->optional),
                'requiredCapabilities' => $this->names(
                    $module->requiredCapabilities,
                ),
                'providedCapabilities' => $provided,
            ];

            if ($schema === 2) {
                $record['version'] = $module->version?->value;
                $record['dependencyVersions'] = $this->encodeRanges(
                    $module->dependencyVersions,
                );
            }

            $records[] = $record;
        }

        try {
            $json = json_encode(
                ['schema' => $schema, 'modules' => $records],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $previous) {
            throw new ModuleCacheException(
                message: 'Module metadata could not be encoded.',
                previous: $previous,
            );
        }

        $this->assertSize($json);

        return $json;
    }

    /**
     * @return list<ModuleDefinition>
     */
    public function decode(string $json): array
    {
        $this->assertSize($json);

        try {
            $document = json_decode(
                $json,
                associative: false,
                depth: 16,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $previous) {
            throw new ModuleCacheException(
                message: 'Module metadata contains invalid JSON.',
                previous: $previous,
            );
        }

        $document = $this->object($document, ['schema', 'modules']);
        $schema = $document->schema;

        if ($schema !== 1 && $schema !== 2) {
            throw new ModuleCacheException(
                'The module metadata schema is unsupported.',
            );
        }

        $fields = [
            'id',
            'required',
            'optional',
            'requiredCapabilities',
            'providedCapabilities',
        ];

        if ($schema === 2) {
            $fields[] = 'version';
            $fields[] = 'dependencyVersions';
        }

        $records = $this->items($document->modules);
        $modules = [];

        try {
            foreach ($records as $record) {
                $record = $this->object($record, $fields);

                $required = [];
                $optional = [];
                $capabilities = [];
                $provisions = [];

                foreach ($this->items($record->required) as $name) {
                    $required[] = new ModuleIdentifier($this->string($name));
                }

                foreach ($this->items($record->optional) as $name) {
                    $optional[] = new ModuleIdentifier($this->string($name));
                }

                foreach ($this->items($record->requiredCapabilities) as $name) {
                    $capabilities[] = new CapabilityIdentifier(
                        $this->string($name),
                    );
                }

                foreach ($this->items($record->providedCapabilities) as $entry) {
                    $entry = $this->object($entry, ['name', 'exclusive']);

                    if (!is_bool($entry->exclusive)) {
                        throw new ModuleCacheException(
                            'A cached capability exclusivity flag must be boolean.',
                        );
                    }

                    $provisions[] = new CapabilityProvision(
                        new CapabilityIdentifier($this->string($entry->name)),
                        $entry->exclusive,
                    );
                }

                $modules[] = new ModuleDefinition(
                    new ModuleIdentifier($this->string($record->id)),
                    $required,
                    $optional,
                    $capabilities,
                    $provisions,
                    version: $schema === 2
                        ? $this->version($record->version)
                        : null,
                    dependencyVersions: $schema === 2
                        ? $this->decodeRanges($record->dependencyVersions)
                        : [],
                );
            }
        } catch (
            InvalidModuleDefinitionException
            | InvalidModuleVersionException
            | InvalidModuleVersionRangeException $previous
        ) {
            throw new ModuleCacheException(
                message: 'Cached module declarations are invalid.',
                previous: $previous,
            );
        }

        return $this->validate($modules);
    }

    /**
     * @param array<string, ModuleVersionRange> $ranges
     *
     * @return list<array{
     *     dependency: string,
     *     minimum: string|null,
     *     maximum: string|null,
     *     includeMinimum: bool,
     *     includeMaximum: bool
     * }>
     */
    private function encodeRanges(array $ranges): array
    {
        ksort($ranges, SORT_STRING);
        $records = [];

        foreach ($ranges as $dependency => $range) {
            $records[] = [
                'dependency' => $dependency,
                'minimum' => $range->minimum?->value,
                'maximum' => $range->maximum?->value,
                'includeMinimum' => $range->includeMinimum,
                'includeMaximum' => $range->includeMaximum,
            ];
        }

        return $records;
    }

    /**
     * @return array<string, ModuleVersionRange>
     */
    private function decodeRanges(mixed $value): array
    {
        $ranges = [];

        foreach ($this->items($value) as $record) {
            $record = $this->object(
                $record,
                [
                    'dependency',
                    'minimum',
                    'maximum',
                    'includeMinimum',
                    'includeMaximum',
                ],
            );

            $dependency = new ModuleIdentifier(
                $this->string($record->dependency),
            );

            if (isset($ranges[$dependency->value])) {
                throw new ModuleCacheException(
                    'A cached dependency version requirement is duplicated.',
                );
            }

            $includeMinimum = $record->includeMinimum;
            $includeMaximum = $record->includeMaximum;

            if (!is_bool($includeMinimum) || !is_bool($includeMaximum)) {
                throw new ModuleCacheException(
                    'Cached version range inclusion flags must be boolean.',
                );
            }

            $minimum = $this->version($record->minimum);
            $maximum = $this->version($record->maximum);

            if (
                ($minimum === null && $includeMinimum)
                || ($maximum === null && $includeMaximum)
            ) {
                throw new ModuleCacheException(
                    'An absent version bound cannot be inclusive.',
                );
            }

            if ($minimum !== null && $maximum !== null) {
                $range = ModuleVersionRange::between(
                    $minimum,
                    $maximum,
                    $includeMinimum,
                    $includeMaximum,
                );
            } elseif ($minimum !== null) {
                $range = ModuleVersionRange::atLeast(
                    $minimum,
                    $includeMinimum,
                );
            } elseif ($maximum !== null) {
                $range = ModuleVersionRange::atMost(
                    $maximum,
                    $includeMaximum,
                );
            } else {
                $range = ModuleVersionRange::any();
            }

            $ranges[$dependency->value] = $range;
        }

        return $ranges;
    }

    private function version(mixed $value): ?ModuleVersion
    {
        return $value === null
            ? null
            : new ModuleVersion($this->string($value));
    }

    /**
     * @param list<ModuleDefinition> $modules
     *
     * @return list<ModuleDefinition>
     */
    private function validate(array $modules): array
    {
        try {
            $ordered = new ModuleDependencyResolver()->resolve($modules);
            new ModuleCapabilityValidator()->validate($ordered);

            return $ordered;
        } catch (
            ModuleResolutionException | CapabilityResolutionException $previous
        ) {
            throw new ModuleCacheException(
                message: 'Cached module relationships are invalid.',
                previous: $previous,
            );
        }
    }

    /**
     * @param list<ModuleIdentifier|CapabilityIdentifier> $identifiers
     *
     * @return list<string>
     */
    private function names(array $identifiers): array
    {
        $names = [];

        foreach ($identifiers as $identifier) {
            $names[] = $identifier->value;
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param list<string> $expected
     */
    private function object(mixed $value, array $expected): stdClass
    {
        if (!$value instanceof stdClass) {
            throw new ModuleCacheException(
                'A module metadata record must be an object.',
            );
        }

        $actual = array_keys(get_object_vars($value));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new ModuleCacheException(
                'A module metadata record has unexpected fields.',
            );
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ModuleCacheException(
                'A module metadata collection must be a JSON list.',
            );
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ModuleCacheException(
                'A module metadata identifier must be a string.',
            );
        }

        return $value;
    }

    private function assertSize(string $json): void
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new ModuleCacheException(
                'Module metadata exceeds the size limit.',
            );
        }
    }
}
