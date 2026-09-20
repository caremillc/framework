<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleDependencyResolver;
use Careminate\Module\ModuleIdentifier;
use Closure;
use PHPUnit\Framework\TestCase;

final class ModuleDependencyResolverTest extends TestCase
{
    public function testEmptyInputProducesAnEmptyOrder(): void
    {
        self::assertSame(
            [],
            new ModuleDependencyResolver()->resolve([]),
        );
    }

    public function testDependenciesPrecedeConsumersRegardlessOfInputOrder(): void
    {
        $identity = self::definition('identity');
        $catalog = self::definition('catalog', ['identity']);
        $billing = self::definition('billing', ['catalog']);
        $audit = self::definition('audit');

        $resolver = new ModuleDependencyResolver();

        $first = $resolver->resolve([$billing, $identity, $audit, $catalog]);
        $second = $resolver->resolve([$catalog, $audit, $identity, $billing]);

        self::assertSame(
            ['audit', 'identity', 'catalog', 'billing'],
            self::names($first),
        );
        self::assertSame($first, $second);
        self::assertSame($billing, $first[3]);
    }

    public function testEnabledOptionalDependencyPrecedesConsumer(): void
    {
        $result = new ModuleDependencyResolver()->resolve([
            self::definition('billing', optional: ['search']),
            self::definition('search'),
        ]);

        self::assertSame(['search', 'billing'], self::names($result));
    }

    public function testAbsentOptionalDependencyIsIgnored(): void
    {
        $result = new ModuleDependencyResolver()->resolve([
            self::definition('billing', optional: ['search']),
        ]);

        self::assertSame(['billing'], self::names($result));
    }

    public function testDisabledOptionalDependencyIsIgnored(): void
    {
        $result = new ModuleDependencyResolver()->resolve(
            [
                self::definition('billing', optional: ['search']),
                self::definition('search'),
            ],
            [new ModuleIdentifier('search')],
        );

        self::assertSame(['billing'], self::names($result));
    }

    public function testDisabledModuleDependenciesAreNotResolved(): void
    {
        $result = new ModuleDependencyResolver()->resolve(
            [
                self::definition('billing'),
                self::definition('search', ['missing']),
            ],
            [new ModuleIdentifier('search')],
        );

        self::assertSame(['billing'], self::names($result));
    }

    public function testMissingRequiredDependencyReportsItsConsumer(): void
    {
        $failure = self::captureFailure(
            static fn (): array => new ModuleDependencyResolver()->resolve([
                self::definition('billing', ['identity']),
            ]),
        );

        self::assertSame(
            'A required module is not defined.',
            $failure->getMessage(),
        );
        self::assertSame(['billing', 'identity'], $failure->modulePath());
    }

    public function testDisabledRequiredDependencyIsRejected(): void
    {
        $failure = self::captureFailure(
            static fn (): array => new ModuleDependencyResolver()->resolve(
                [
                    self::definition('billing', ['identity']),
                    self::definition('identity'),
                ],
                [new ModuleIdentifier('identity')],
            ),
        );

        self::assertSame(
            'A required module is disabled.',
            $failure->getMessage(),
        );
        self::assertSame(['billing', 'identity'], $failure->modulePath());
    }

    public function testDuplicateDefinitionsAreRejectedEvenWhenDisabled(): void
    {
        $failure = self::captureFailure(
            static fn (): array => new ModuleDependencyResolver()->resolve(
                [
                    self::definition('billing'),
                    self::definition('billing'),
                ],
                [new ModuleIdentifier('billing')],
            ),
        );

        self::assertSame(
            'A module identifier is defined more than once.',
            $failure->getMessage(),
        );
        self::assertSame(['billing'], $failure->modulePath());
    }

    public function testUnknownDisabledIdentifierIsRejected(): void
    {
        $failure = self::captureFailure(
            static fn (): array => new ModuleDependencyResolver()->resolve(
                [],
                [new ModuleIdentifier('billing')],
            ),
        );

        self::assertSame(
            'A disabled module identifier is not defined.',
            $failure->getMessage(),
        );
        self::assertSame(['billing'], $failure->modulePath());
    }

    public function testCycleDiagnosticsExcludeTheLeadingConsumerChain(): void
    {
        $failure = self::captureFailure(
            static fn (): array => new ModuleDependencyResolver()->resolve([
                self::definition('app', ['billing']),
                self::definition('billing', ['catalog']),
                self::definition('catalog', ['billing']),
            ]),
        );

        self::assertSame(
            'The enabled modules contain a dependency cycle.',
            $failure->getMessage(),
        );
        self::assertSame(
            ['billing', 'catalog', 'billing'],
            $failure->modulePath(),
        );
    }

    public function testEnabledOptionalEdgesParticipateInCycleDetection(): void
    {
        $failure = self::captureFailure(
            static fn (): array => new ModuleDependencyResolver()->resolve([
                self::definition('billing', optional: ['search']),
                self::definition('search', ['billing']),
            ]),
        );

        self::assertSame(
            ['billing', 'search', 'billing'],
            $failure->modulePath(),
        );
    }

    public function testRepeatedDisabledIdentifiersAreIdempotent(): void
    {
        $result = new ModuleDependencyResolver()->resolve(
            [self::definition('billing')],
            [
                new ModuleIdentifier('billing'),
                new ModuleIdentifier('billing'),
            ],
        );

        self::assertSame([], $result);
    }

    public function testResolverCanBeReusedAfterFailure(): void
    {
        $resolver = new ModuleDependencyResolver();

        $failure = self::captureFailure(
            static fn (): array => $resolver->resolve([
                self::definition('billing', ['missing']),
            ]),
        );

        self::assertSame(['billing', 'missing'], $failure->modulePath());

        self::assertSame(
            ['catalog'],
            self::names($resolver->resolve([self::definition('catalog')])),
        );
    }

    public function testDefinitionsMayBeProvidedByAGenerator(): void
    {
        $definitions = static function (): iterable {
            yield self::definition('billing', ['identity']);
            yield self::definition('identity');
        };

        $result = new ModuleDependencyResolver()->resolve($definitions());

        self::assertSame(['identity', 'billing'], self::names($result));
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function definition(
        string $name,
        array $required = [],
        array $optional = [],
    ): ModuleDefinition {
        $identifier = static fn (string $value): ModuleIdentifier =>
            new ModuleIdentifier($value);

        return new ModuleDefinition(
            new ModuleIdentifier($name),
            array_map($identifier, $required),
            array_map($identifier, $optional),
        );
    }

    /**
     * @param list<ModuleDefinition> $definitions
     *
     * @return list<string>
     */
    private static function names(array $definitions): array
    {
        return array_map(
            static fn (ModuleDefinition $definition): string =>
                $definition->id->value,
            $definitions,
        );
    }

    /**
     * @param Closure(): list<ModuleDefinition> $operation
     */
    private static function captureFailure(
        Closure $operation,
    ): ModuleResolutionException {
        try {
            $operation();
        } catch (ModuleResolutionException $exception) {
            return $exception;
        }

        self::fail('Invalid module relationships must fail resolution.');
    }
}
