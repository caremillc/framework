<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\Exception\ProviderRegistrationException;
use Careminate\Module\ModuleDependencyResolver;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleRegistration;
use Throwable;

/**
 * Validates enabled modules and merges their owned service definitions.
 *
 * @internal
 */
final class ModuleServiceCompiler
{
    /**
     * @param iterable<ModuleRegistration> $registrations
     * @param iterable<ModuleIdentifier> $disabled
     */
    public function compile(
        iterable $registrations,
        iterable $disabled = [],
    ): ModuleServiceDefinitions {
        /** @var array<string, ModuleRegistration> $registered */
        $registered = [];

        $definitions = [];

        foreach ($registrations as $registration) {
            $name = $registration->definition->id->value;

            if (isset($registered[$name])) {
                throw new ModuleResolutionException(
                    'A module identifier is defined more than once.',
                    [$name],
                );
            }

            $registered[$name] = $registration;
            $definitions[] = $registration->definition;
        }

        $ordered = new ModuleDependencyResolver()->resolve(
            $definitions,
            $disabled,
        );

        new ModuleCapabilityValidator()->validate($ordered);

        $builder = new DefinitionBuilder();

        /** @var array<string, ModuleIdentifier> $owners */
        $owners = [];

        /** @var list<string> $exports */
        $exports = [];

        foreach ($ordered as $definition) {
            $owner = $definition->id;
            $registration = $registered[$owner->value];
            $registry = new OwnedServiceRegistry($owner);

            try {
                try {
                    foreach ($registration->providers as $provider) {
                        $provider->register($registry);
                    }
                } finally {
                    $registry->seal();
                }

                $snapshot = $registry->seal();

                foreach ($snapshot->definitions->services as $service) {
                    $builder->add($service);
                    $owners['entry:' . $service->id] = $owner;
                }

                foreach ($snapshot->exportedServices as $id) {
                    $exports[] = $id;
                }
            } catch (Throwable $previous) {
                throw new ProviderRegistrationException($owner, $previous);
            }
        }

        return new ModuleServiceDefinitions(
            $builder->build(),
            $ordered,
            $owners,
            $exports,
        );
    }
}
