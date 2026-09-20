<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Application\BootstrapperInterface;
use Careminate\Application\TerminableBootstrapperInterface;
use Psr\Container\ContainerInterface;

/**
 * Binds lifecycle handlers to a container supplied by trusted composition.
 *
 * This factory does not establish module identity or enforce access itself.
 *
 * @internal
 */
final class ModuleScopedBootstrapperFactory
{
    public function wrap(
        BootstrapperInterface $bootstrapper,
        ContainerInterface $moduleContainer,
    ): BootstrapperInterface {
        if ($bootstrapper instanceof TerminableBootstrapperInterface) {
            return new class (
                $bootstrapper,
                $moduleContainer,
            ) implements TerminableBootstrapperInterface {
                public function __construct(
                    private readonly TerminableBootstrapperInterface $delegate,
                    private readonly ContainerInterface $moduleContainer,
                ) {
                }

                public function bootstrap(ContainerInterface $container): void
                {
                    $this->delegate->bootstrap($this->moduleContainer);
                }

                public function terminate(ContainerInterface $container): void
                {
                    $this->delegate->terminate($this->moduleContainer);
                }
            };
        }

        return new class (
            $bootstrapper,
            $moduleContainer,
        ) implements BootstrapperInterface {
            public function __construct(
                private readonly BootstrapperInterface $delegate,
                private readonly ContainerInterface $moduleContainer,
            ) {
            }

            public function bootstrap(ContainerInterface $container): void
            {
                $this->delegate->bootstrap($this->moduleContainer);
            }
        };
    }
}
