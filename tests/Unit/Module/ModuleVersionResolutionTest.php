<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\Internal\ModuleMetadataCodec;
use Careminate\Module\Internal\ModuleServiceCompiler;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleDependencyResolver;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleRegistration;
use Careminate\Module\ModuleVersion;
use Careminate\Module\ModuleVersionRange;
use Careminate\Module\ServiceProviderInterface;
use Careminate\Module\ServiceRegistryInterface;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleVersionResolutionTest extends TestCase
{
    #[DataProvider('dependencyModes')]
    public function testCompatibleDependencyPreservesResolvedOrder(
        bool $optional,
    ): void {
        $dependency = self::dependency('1.5.0');
        $consumer = self::consumer($optional);

        $resolved = new ModuleDependencyResolver()->resolve([
            $consumer,
            $dependency,
        ]);

        self::assertSame([$dependency, $consumer], $resolved);
    }

    #[DataProvider('dependencyModes')]
    public function testIncompatibleEnabledDependencyIsRejected(
        bool $optional,
    ): void {
        try {
            new ModuleDependencyResolver()->resolve([
                self::consumer($optional),
                self::dependency('2.0.0'),
            ]);
        } catch (ModuleResolutionException $exception) {
            self::assertSame(
                'A module dependency version does not satisfy its required range.',
                $exception->getMessage(),
            );
            self::assertSame(
                ['consumer', 'base'],
                $exception->modulePath(),
            );

            return;
        }

        self::fail('An incompatible enabled dependency must be rejected.');
    }

    #[DataProvider('dependencyModes')]
    public function testConstrainedUnversionedDependencyIsRejected(
        bool $optional,
    ): void {
        try {
            new ModuleDependencyResolver()->resolve([
                self::consumer($optional),
                self::dependency(null),
            ]);
        } catch (ModuleResolutionException $exception) {
            self::assertSame(
                'A constrained module dependency has no declared version.',
                $exception->getMessage(),
            );
            self::assertSame(
                ['consumer', 'base'],
                $exception->modulePath(),
            );

            return;
        }

        self::fail('A constrained dependency must declare its version.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function dependencyModes(): iterable
    {
        yield 'required' => [false];
        yield 'optional and enabled' => [true];
    }

    public function testAbsentOptionalDependencyIsIgnored(): void
    {
        $consumer = self::consumer(optional: true);

        self::assertSame(
            [$consumer],
            new ModuleDependencyResolver()->resolve([$consumer]),
        );
    }

    public function testDisabledOptionalDependencyIsIgnored(): void
    {
        $consumer = self::consumer(optional: true);

        self::assertSame(
            [$consumer],
            new ModuleDependencyResolver()->resolve(
                [$consumer, self::dependency('9.0.0')],
                [new ModuleIdentifier('base')],
            ),
        );
    }

    public function testDisabledConsumerRequirementsAreIgnored(): void
    {
        $dependency = self::dependency('9.0.0');

        self::assertSame(
            [$dependency],
            new ModuleDependencyResolver()->resolve(
                [self::consumer(), $dependency],
                [new ModuleIdentifier('consumer')],
            ),
        );
    }

    public function testUnconstrainedDependencyMayRemainUnversioned(): void
    {
        $dependency = self::dependency(null);

        $consumer = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            required: [new ModuleIdentifier('base')],
        );

        self::assertSame(
            [$dependency, $consumer],
            new ModuleDependencyResolver()->resolve([
                $consumer,
                $dependency,
            ]),
        );
    }

    public function testExplicitAnyRangeStillRequiresAKnownVersion(): void
    {
        $consumer = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            required: [new ModuleIdentifier('base')],
            dependencyVersions: ['base' => ModuleVersionRange::any()],
        );

        $this->expectException(ModuleResolutionException::class);
        $this->expectExceptionMessage(
            'A constrained module dependency has no declared version.',
        );

        new ModuleDependencyResolver()->resolve([
            $consumer,
            self::dependency(null),
        ]);
    }

    public function testMissingRequiredDependencyRetainsExistingDiagnostic(): void
    {
        $this->expectException(ModuleResolutionException::class);
        $this->expectExceptionMessage('A required module is not defined.');

        new ModuleDependencyResolver()->resolve([self::consumer()]);
    }

    public function testDisabledRequiredDependencyRetainsExistingDiagnostic(): void
    {
        $this->expectException(ModuleResolutionException::class);
        $this->expectExceptionMessage('A required module is disabled.');

        new ModuleDependencyResolver()->resolve(
            [self::consumer(), self::dependency('9.0.0')],
            [new ModuleIdentifier('base')],
        );
    }

    public function testCycleDiagnosticPrecedesVersionMismatch(): void
    {
        $base = new ModuleDefinition(
            new ModuleIdentifier('base'),
            required: [new ModuleIdentifier('consumer')],
            version: new ModuleVersion('9.0.0'),
        );

        try {
            new ModuleDependencyResolver()->resolve([
                self::consumer(),
                $base,
            ]);
        } catch (ModuleResolutionException $exception) {
            self::assertSame(
                'The enabled modules contain a dependency cycle.',
                $exception->getMessage(),
            );
            self::assertSame(
                ['base', 'consumer', 'base'],
                $exception->modulePath(),
            );

            return;
        }

        self::fail('The existing cycle diagnostic must be preserved.');
    }

    public function testRequirementFailureOrderIsIndependentOfInputOrder(): void
    {
        $alpha = new ModuleDefinition(
            new ModuleIdentifier('alpha'),
            version: new ModuleVersion('9.0.0'),
        );

        $zeta = new ModuleDefinition(
            new ModuleIdentifier('zeta'),
            version: new ModuleVersion('9.0.0'),
        );

        $range = ModuleVersionRange::exactly(new ModuleVersion('1.0.0'));

        foreach ([false, true] as $reverse) {
            $consumer = new ModuleDefinition(
                new ModuleIdentifier('consumer'),
                required: $reverse
                    ? [$zeta->id, $alpha->id]
                    : [$alpha->id, $zeta->id],
                dependencyVersions: $reverse
                    ? ['zeta' => $range, 'alpha' => $range]
                    : ['alpha' => $range, 'zeta' => $range],
            );

            try {
                new ModuleDependencyResolver()->resolve(
                    $reverse
                        ? [$zeta, $consumer, $alpha]
                        : [$alpha, $consumer, $zeta],
                );
            } catch (ModuleResolutionException $exception) {
                self::assertSame(
                    ['consumer', 'alpha'],
                    $exception->modulePath(),
                );

                continue;
            }

            self::fail('Both input orders must reject the same dependency.');
        }
    }

    public function testMismatchIsDetectedBeforeProviderRegistration(): void
    {
        $provider = new class () implements ServiceProviderInterface {
            public function register(ServiceRegistryInterface $registry): void
            {
                throw new Error(
                    'Provider registration must remain unreachable.',
                );
            }
        };

        $this->expectException(ModuleResolutionException::class);
        $this->expectExceptionMessage(
            'A module dependency version does not satisfy its required range.',
        );

        new ModuleServiceCompiler()->compile([
            new ModuleRegistration(self::consumer(), $provider),
            new ModuleRegistration(self::dependency('9.0.0'), $provider),
        ]);
    }

    public function testMetadataEncodingRejectsIncompatibleDeclarations(): void
    {
        try {
            new ModuleMetadataCodec()->encode([
                self::consumer(),
                self::dependency('9.0.0'),
            ]);
        } catch (ModuleCacheException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(ModuleResolutionException::class, $previous);
            self::assertSame(
                ['consumer', 'base'],
                $previous->modulePath(),
            );

            return;
        }

        self::fail('Incompatible metadata must not be encoded.');
    }

    public function testMetadataDecodingRevalidatesDependencyVersions(): void
    {
        $codec = new ModuleMetadataCodec();

        $json = $codec->encode([
            self::consumer(),
            self::dependency('1.5.0'),
        ]);

        $incompatible = str_replace(
            '"version":"1.5.0"',
            '"version":"9.0.0"',
            $json,
        );

        self::assertNotSame($json, $incompatible);

        try {
            $codec->decode($incompatible);
        } catch (ModuleCacheException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(ModuleResolutionException::class, $previous);
            self::assertSame(
                ['consumer', 'base'],
                $previous->modulePath(),
            );

            return;
        }

        self::fail('Decoded metadata must revalidate dependency versions.');
    }

    private static function dependency(?string $version): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleIdentifier('base'),
            version: $version === null ? null : new ModuleVersion($version),
        );
    }

    private static function consumer(bool $optional = false): ModuleDefinition
    {
        $dependency = new ModuleIdentifier('base');

        return new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            required: $optional ? [] : [$dependency],
            optional: $optional ? [$dependency] : [],
            dependencyVersions: [
                'base' => ModuleVersionRange::between(
                    new ModuleVersion('1.0.0'),
                    new ModuleVersion('2.0.0-0'),
                ),
            ],
        );
    }
}
