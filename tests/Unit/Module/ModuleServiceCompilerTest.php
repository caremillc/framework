<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use ArrayObject;
use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\Exception\ProviderRegistrationException;
use Careminate\Module\Internal\ModuleServiceCompiler;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleRegistration;
use Careminate\Module\ServiceProviderInterface;
use Careminate\Module\ServiceRegistryInterface;
use Closure;
use Error;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ModuleServiceCompilerTest extends TestCase
{
    public function testProvidersFollowModuleOrderAndPreserveOwnership(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $identity = new ModuleIdentifier('identity');
        $billing = new ModuleIdentifier('billing');

        $result = new ModuleServiceCompiler()->compile([
            new ModuleRegistration(
                new ModuleDefinition($billing, required: [$identity]),
                self::provider(
                    static function (ServiceRegistryInterface $services) use (
                        $events,
                    ): void {
                        $events->append('billing');
                        $services->value('billing.currency', 'USD');
                    },
                ),
            ),
            new ModuleRegistration(
                new ModuleDefinition($identity),
                self::provider(
                    static function (ServiceRegistryInterface $services) use (
                        $events,
                    ): void {
                        $events->append('identity');
                        $services->value('identity.enabled', true);
                    },
                ),
            ),
        ]);

        self::assertSame(['identity', 'billing'], $events->getArrayCopy());
        self::assertSame($identity, $result->modules[0]->id);
        self::assertSame($billing, $result->modules[1]->id);
        self::assertSame($identity, $result->ownerOf('identity.enabled'));
        self::assertSame($billing, $result->ownerOf('billing.currency'));
        self::assertNull($result->ownerOf('missing'));

        $container = new CompiledDefinitionContainerFactory()->create(
            $result->definitions,
        );

        self::assertTrue($container->isFrozen());
        self::assertTrue($container->get('identity.enabled'));
        self::assertSame('USD', $container->get('billing.currency'));
    }

    public function testDisabledProviderIsNeverInvoked(): void
    {
        $result = new ModuleServiceCompiler()->compile(
            [
                new ModuleRegistration(
                    new ModuleDefinition(new ModuleIdentifier('billing')),
                ),
                new ModuleRegistration(
                    new ModuleDefinition(new ModuleIdentifier('search')),
                    self::provider(
                        static function (ServiceRegistryInterface $services): void {
                            self::fail('A disabled provider must not execute.');
                        },
                    ),
                ),
            ],
            [new ModuleIdentifier('search')],
        );

        self::assertCount(1, $result->modules);
        self::assertSame('billing', $result->modules[0]->id->value);
        self::assertSame([], $result->definitions->services);
    }

    public function testGraphValidationPrecedesEveryProvider(): void
    {
        $provider = self::provider(
            static function (ServiceRegistryInterface $services): void {
                self::fail('Providers must not run before graph validation.');
            },
        );

        $this->expectException(ModuleResolutionException::class);

        new ModuleServiceCompiler()->compile([
            new ModuleRegistration(
                new ModuleDefinition(new ModuleIdentifier('audit')),
                $provider,
            ),
            new ModuleRegistration(
                new ModuleDefinition(
                    new ModuleIdentifier('billing'),
                    required: [new ModuleIdentifier('missing')],
                ),
                $provider,
            ),
        ]);
    }

    public function testDuplicateModulesFailBeforeProviderExecution(): void
    {
        $provider = self::provider(
            static function (ServiceRegistryInterface $services): void {
                self::fail('Duplicate modules must fail before execution.');
            },
        );

        $this->expectException(ModuleResolutionException::class);

        new ModuleServiceCompiler()->compile([
            new ModuleRegistration(
                new ModuleDefinition(new ModuleIdentifier('billing')),
                $provider,
            ),
            new ModuleRegistration(
                new ModuleDefinition(new ModuleIdentifier('billing')),
                $provider,
            ),
        ]);
    }

    public function testProvidersWithinAModuleFollowDeclarationOrder(): void
    {
        $result = new ModuleServiceCompiler()->compile([
            new ModuleRegistration(
                new ModuleDefinition(new ModuleIdentifier('billing')),
                self::provider(
                    static function (ServiceRegistryInterface $services): void {
                        $services->value('first', 1);
                    },
                ),
                self::provider(
                    static function (ServiceRegistryInterface $services): void {
                        $services->value('second', 2);
                    },
                ),
            ),
        ]);

        self::assertSame('first', $result->definitions->services[0]->id);
        self::assertSame('second', $result->definitions->services[1]->id);
        self::assertSame(
            $result->ownerOf('first'),
            $result->ownerOf('second'),
        );
    }

    public function testCrossModuleCollisionAbortsBeforeLaterProviders(): void
    {
        $failure = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile([
                    new ModuleRegistration(
                        new ModuleDefinition(new ModuleIdentifier('alpha')),
                        self::provider(
                            static function (ServiceRegistryInterface $services): void {
                                $services->value('shared', null);
                            },
                        ),
                    ),
                    new ModuleRegistration(
                        new ModuleDefinition(new ModuleIdentifier('beta')),
                        self::provider(
                            static function (ServiceRegistryInterface $services): void {
                                $services->value('shared', 'replacement');
                            },
                        ),
                    ),
                    new ModuleRegistration(
                        new ModuleDefinition(new ModuleIdentifier('gamma')),
                        self::provider(
                            static function (ServiceRegistryInterface $services): void {
                                self::fail('Compilation must stop at the collision.');
                            },
                        ),
                    ),
                ]),
        );

        self::assertSame('beta', $failure->owner->value);
        self::assertInstanceOf(
            DefinitionException::class,
            $failure->getPrevious(),
        );
    }

    public function testProviderFailurePreservesCauseAndSealsItsRegistry(): void
    {
        $original = new Error('Provider failed.');

        /** @var ArrayObject<int, ServiceRegistryInterface> $retained */
        $retained = new ArrayObject();

        $failure = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile([
                    new ModuleRegistration(
                        new ModuleDefinition(new ModuleIdentifier('billing')),
                        self::provider(
                            static function (ServiceRegistryInterface $services) use (
                                $original,
                                $retained,
                            ): void {
                                $retained->append($services);
                                $services->value('partial', true);

                                throw $original;
                            },
                        ),
                    ),
                ]),
        );

        self::assertSame($original, $failure->getPrevious());
        self::assertSame('billing', $failure->owner->value);

        $registry = $retained->offsetGet(0);

        self::assertInstanceOf(ServiceRegistryInterface::class, $registry);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The module service registry is sealed.');

        $registry->value('late', true);
    }

    public function testCompilerRetainsNoContributionsAfterFailure(): void
    {
        $compiler = new ModuleServiceCompiler();
        $original = new Error('Registration stopped.');

        $failure = self::captureFailure(
            static fn (): ModuleServiceDefinitions => $compiler->compile([
                new ModuleRegistration(
                    new ModuleDefinition(new ModuleIdentifier('billing')),
                    self::provider(
                        static function (ServiceRegistryInterface $services) use (
                            $original,
                        ): void {
                            $services->value('partial', true);

                            throw $original;
                        },
                    ),
                ),
            ]),
        );

        self::assertSame($original, $failure->getPrevious());

        $result = $compiler->compile([]);

        self::assertSame([], $result->modules);
        self::assertSame([], $result->definitions->services);
        self::assertNull($result->ownerOf('partial'));
    }

    /**
     * @param Closure(ServiceRegistryInterface): void $operation
     */
    private static function provider(
        Closure $operation,
    ): ServiceProviderInterface {
        return new class ($operation) implements ServiceProviderInterface {
            /**
             * @param Closure(ServiceRegistryInterface): void $operation
             */
            public function __construct(
                private readonly Closure $operation,
            ) {
            }

            public function register(ServiceRegistryInterface $services): void
            {
                ($this->operation)($services);
            }
        };
    }

    /**
     * @param Closure(): ModuleServiceDefinitions $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): ProviderRegistrationException {
        try {
            $operation();
        } catch (ProviderRegistrationException $exception) {
            return $exception;
        }

        self::fail('Provider compilation must fail.');
    }
}
