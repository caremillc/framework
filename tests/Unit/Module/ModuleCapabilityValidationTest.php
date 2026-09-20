<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\CapabilityIdentifier;
use Careminate\Module\CapabilityProvision;
use Careminate\Module\Exception\CapabilityResolutionException;
use Careminate\Module\Internal\ModuleServiceCompiler;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleRegistration;
use Careminate\Module\ServiceProviderInterface;
use Careminate\Module\ServiceRegistryInterface;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleCapabilityValidationTest extends TestCase
{
    public function testRequiredCapabilityCanHaveOneExclusiveProvider(): void
    {
        $result = new ModuleServiceCompiler()->compile([
            self::registration('consumer', requires: ['files']),
            self::registration('storage', provides: ['files' => true]),
        ]);

        self::assertCount(2, $result->modules);
        self::assertSame('consumer', $result->modules[0]->id->value);
        self::assertSame('storage', $result->modules[1]->id->value);
    }

    public function testMultipleSharedProvidersAreAccepted(): void
    {
        $result = new ModuleServiceCompiler()->compile([
            self::registration('consumer', requires: ['search']),
            self::registration('alpha', provides: ['search' => false]),
            self::registration('beta', provides: ['search' => false]),
        ]);

        self::assertCount(3, $result->modules);
    }

    public function testMissingCapabilityFailsBeforeAnyProviderExecutes(): void
    {
        $failure = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile([
                    self::registration(
                        'audit',
                        provider: self::forbiddenProvider(),
                    ),
                    self::registration('consumer', requires: ['files']),
                ]),
        );

        self::assertSame(
            'A required capability has no enabled provider.',
            $failure->getMessage(),
        );
        self::assertSame('files', $failure->capability->value);
        self::assertSame(['consumer'], self::moduleNames($failure));
    }

    #[DataProvider('exclusiveCombinations')]
    public function testExclusiveConflictsFailBeforeProviderExecution(
        bool $alphaExclusive,
        bool $betaExclusive,
    ): void {
        $failure = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile([
                    self::registration(
                        'beta',
                        provides: ['files' => $betaExclusive],
                        provider: self::forbiddenProvider(),
                    ),
                    self::registration(
                        'alpha',
                        provides: ['files' => $alphaExclusive],
                        provider: self::forbiddenProvider(),
                    ),
                ]),
        );

        self::assertSame(
            'An exclusive capability has multiple enabled providers.',
            $failure->getMessage(),
        );
        self::assertSame('files', $failure->capability->value);
        self::assertSame(['alpha', 'beta'], self::moduleNames($failure));
    }

    public function testDisabledProvisionCannotSatisfyARequirement(): void
    {
        $failure = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile(
                    [
                        self::registration('consumer', requires: ['files']),
                        self::registration(
                            'storage',
                            provides: ['files' => true],
                            provider: self::forbiddenProvider(),
                        ),
                    ],
                    [new ModuleIdentifier('storage')],
                ),
        );

        self::assertSame('files', $failure->capability->value);
        self::assertSame(['consumer'], self::moduleNames($failure));
    }

    public function testDisabledRequirementsAndConflictsAreIgnored(): void
    {
        $result = new ModuleServiceCompiler()->compile(
            [
                self::registration('alpha', provides: ['files' => true]),
                self::registration(
                    'beta',
                    requires: ['missing'],
                    provides: ['files' => true],
                    provider: self::forbiddenProvider(),
                ),
            ],
            [new ModuleIdentifier('beta')],
        );

        self::assertCount(1, $result->modules);
        self::assertSame('alpha', $result->modules[0]->id->value);
    }

    public function testSelfProvidedRequirementIsSatisfied(): void
    {
        $result = new ModuleServiceCompiler()->compile([
            self::registration(
                'storage',
                requires: ['files'],
                provides: ['files' => true],
            ),
        ]);

        self::assertCount(1, $result->modules);
    }

    public function testDiagnosticsDoNotDependOnDiscoveryOrder(): void
    {
        $alpha = self::registration('alpha', provides: ['files' => false]);
        $beta = self::registration('beta', provides: ['files' => true]);

        $first = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile([$alpha, $beta]),
        );

        $second = self::captureFailure(
            static fn (): ModuleServiceDefinitions =>
                new ModuleServiceCompiler()->compile([$beta, $alpha]),
        );

        self::assertSame($first->getMessage(), $second->getMessage());
        self::assertSame(
            $first->capability->value,
            $second->capability->value,
        );
        self::assertSame(
            self::moduleNames($first),
            self::moduleNames($second),
        );
    }

    public function testSuccessfulValidationAllowsServiceRegistration(): void
    {
        $provider = new class () implements ServiceProviderInterface {
            public function register(ServiceRegistryInterface $services): void
            {
                $services->value('storage.ready', true);
            }
        };

        $result = new ModuleServiceCompiler()->compile([
            self::registration(
                'storage',
                provides: ['files' => true],
                provider: $provider,
            ),
        ]);

        self::assertCount(1, $result->definitions->services);
        self::assertSame('storage.ready', $result->definitions->services[0]->id);
        self::assertTrue($result->definitions->services[0]->value());

        $owner = $result->ownerOf('storage.ready');

        self::assertInstanceOf(ModuleIdentifier::class, $owner);
        self::assertSame('storage', $owner->value);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function exclusiveCombinations(): iterable
    {
        yield 'both exclusive' => [true, true];
        yield 'first exclusive' => [true, false];
        yield 'second exclusive' => [false, true];
    }

    /**
     * @param list<string> $requires
     * @param array<string, bool> $provides
     */
    private static function registration(
        string $name,
        array $requires = [],
        array $provides = [],
        ?ServiceProviderInterface $provider = null,
    ): ModuleRegistration {
        $requirements = array_map(
            static fn (string $value): CapabilityIdentifier =>
                new CapabilityIdentifier($value),
            $requires,
        );

        $provisions = [];

        foreach ($provides as $capability => $exclusive) {
            $provisions[] = new CapabilityProvision(
                new CapabilityIdentifier($capability),
                $exclusive,
            );
        }

        $definition = new ModuleDefinition(
            new ModuleIdentifier($name),
            requiredCapabilities: $requirements,
            providedCapabilities: $provisions,
        );

        if ($provider === null) {
            return new ModuleRegistration($definition);
        }

        return new ModuleRegistration($definition, $provider);
    }

    private static function forbiddenProvider(): ServiceProviderInterface
    {
        return new class () implements ServiceProviderInterface {
            public function register(ServiceRegistryInterface $services): void
            {
                self::failUnexpectedExecution();
            }

            private static function failUnexpectedExecution(): never
            {
                TestCase::fail('This provider must not execute.');
            }
        };
    }

    /**
     * @return list<string>
     */
    private static function moduleNames(
        CapabilityResolutionException $exception,
    ): array {
        return array_map(
            static fn (ModuleIdentifier $module): string => $module->value,
            $exception->modules(),
        );
    }

    /**
     * @param Closure(): ModuleServiceDefinitions $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): CapabilityResolutionException {
        try {
            $operation();
        } catch (CapabilityResolutionException $exception) {
            return $exception;
        }

        self::fail('Invalid capability declarations must fail compilation.');
    }
}
