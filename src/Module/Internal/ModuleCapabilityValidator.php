<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\CapabilityIdentifier;
use Careminate\Module\Exception\CapabilityResolutionException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;

/**
 * Validates capabilities across an already resolved enabled module set.
 *
 * @internal
 */
final class ModuleCapabilityValidator
{
    /**
     * @param list<ModuleDefinition> $modules
     */
    public function validate(array $modules): void
    {
        usort(
            $modules,
            static fn (ModuleDefinition $first, ModuleDefinition $second): int =>
                strcmp($first->id->value, $second->id->value),
        );

        /** @var array<string, list<ModuleIdentifier>> $providers */
        $providers = [];

        /** @var array<string, true> $exclusive */
        $exclusive = [];

        foreach ($modules as $module) {
            foreach ($module->providedCapabilities as $provision) {
                $name = $provision->capability->value;

                $providers[$name][] = $module->id;

                if ($provision->exclusive) {
                    $exclusive[$name] = true;
                }
            }
        }

        ksort($providers, SORT_STRING);

        foreach ($providers as $name => $owners) {
            if (count($owners) > 1 && isset($exclusive[$name])) {
                throw new CapabilityResolutionException(
                    'An exclusive capability has multiple enabled providers.',
                    new CapabilityIdentifier($name),
                    $owners,
                );
            }
        }

        foreach ($modules as $module) {
            $requirements = $module->requiredCapabilities;

            usort(
                $requirements,
                static fn (
                    CapabilityIdentifier $first,
                    CapabilityIdentifier $second,
                ): int => strcmp($first->value, $second->value),
            );

            foreach ($requirements as $capability) {
                if (!isset($providers[$capability->value])) {
                    throw new CapabilityResolutionException(
                        'A required capability has no enabled provider.',
                        $capability,
                        [$module->id],
                    );
                }
            }
        }
    }
}
