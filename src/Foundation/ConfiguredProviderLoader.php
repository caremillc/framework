<?php

declare(strict_types=1);

namespace Careminate\Foundation;

use Careminate\Config\Configuration;
use InvalidArgumentException;
use ReflectionClass;

final class ConfiguredProviderLoader
{
    public function register(
        Application $application,
        Configuration $configuration
    ): void {
        $classes = $configuration->array('providers.classes');

        if (!array_is_list($classes)) {
            throw new InvalidArgumentException(
                'Configuration key "providers.classes" must be a list.'
            );
        }

        /** @var array<class-string<ServiceProvider>, true> $seen */
        $seen = [];

        /** @var list<class-string<ServiceProvider>> $validated */
        $validated = [];

        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidArgumentException(
                    'Each configured provider must be a non-empty class name.'
                );
            }

            if (!class_exists($class)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configured service provider "%s" does not exist.',
                        $class
                    )
                );
            }

            if (!is_subclass_of($class, ServiceProvider::class)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configured service provider "%s" must implement ServiceProvider.',
                        $class
                    )
                );
            }

            /** @var class-string<ServiceProvider> $class */

            if (isset($seen[$class])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configured service provider "%s" is duplicated.',
                        $class
                    )
                );
            }

            $reflection = new ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configured service provider "%s" is not instantiable.',
                        $class
                    )
                );
            }

            $constructor = $reflection->getConstructor();

            if (
                $constructor !== null
                && (
                    !$constructor->isPublic()
                    || $constructor->getNumberOfRequiredParameters() > 0
                )
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configured service provider "%s" must have a public constructor with no required arguments.',
                        $class
                    )
                );
            }

            $seen[$class] = true;
            $validated[] = $class;
        }

        foreach ($validated as $class) {
            $application->registerProvider(new $class());
        }
    }
}
