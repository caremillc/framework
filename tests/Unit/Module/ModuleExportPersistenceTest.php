<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\ExportingServiceRegistryInterface;
use Careminate\Module\Internal\ModuleArtifactCodec;
use Careminate\Module\Internal\ModuleCacheIdentity;
use Careminate\Module\Internal\ModuleServiceAccessPolicy;
use Careminate\Module\Internal\ModuleServiceCompiler;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleRegistration;
use Careminate\Module\ServiceProviderInterface;
use Careminate\Module\ServiceRegistryInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ModuleExportPersistenceTest extends TestCase
{
    public function testCompilerPreservesExportsAndOwnership(): void
    {
        $snapshot = new ModuleServiceCompiler()->compile([
            self::registration(),
        ]);

        self::assertSame(['public'], $snapshot->exportedServices);
        self::assertSame('base', $snapshot->ownerOf('public')?->value);
        self::assertSame('base', $snapshot->ownerOf('private')?->value);
        self::assertCount(2, $snapshot->definitions->services);
    }

    public function testDisabledModuleContributesNoExports(): void
    {
        $snapshot = new ModuleServiceCompiler()->compile(
            [self::registration()],
            [new ModuleIdentifier('base')],
        );

        self::assertSame([], $snapshot->exportedServices);
        self::assertSame([], $snapshot->modules);
        self::assertSame([], $snapshot->definitions->services);
    }

    public function testArtifactRoundTripPreservesAccessDecisions(): void
    {
        $compiled = new ModuleServiceCompiler()->compile([
            self::registration(),
            new ModuleRegistration(
                new ModuleDefinition(
                    new ModuleIdentifier('consumer'),
                    required: [new ModuleIdentifier('base')],
                ),
            ),
        ]);

        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode($compiled, $identity);

        self::assertStringStartsWith('{"schema":2,', $artifact);

        $restored = $codec->decode(
            $artifact,
            $identity,
            $codec->fingerprint($artifact),
        );

        self::assertSame(['public'], $restored->exportedServices);
        self::assertSame('base', $restored->ownerOf('public')?->value);

        $policy = new ModuleServiceAccessPolicy(
            $restored,
            $restored->exportedServices,
        );

        self::assertTrue(
            $policy->allows(new ModuleIdentifier('consumer'), 'public'),
        );
        self::assertFalse(
            $policy->allows(new ModuleIdentifier('consumer'), 'private'),
        );
        self::assertSame($artifact, $codec->encode($restored, $identity));
    }

    public function testNoExportsRetainsSchemaOne(): void
    {
        $snapshot = new ModuleServiceDefinitions(
            new DefinitionBuilder()->build(),
            [],
            [],
        );

        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode($snapshot, $identity);

        self::assertStringStartsWith('{"schema":1,', $artifact);

        $restored = $codec->decode(
            $artifact,
            $identity,
            $codec->fingerprint($artifact),
        );

        self::assertSame([], $restored->exportedServices);
        self::assertSame($artifact, $codec->encode($restored, $identity));
    }

    public function testBinaryExportIdentifierSurvivesRoundTrip(): void
    {
        $id = "service\x00\xFF";
        $owner = new ModuleIdentifier('base');
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue($id, null));

        $snapshot = new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition($owner)],
            ['entry:' . $id => $owner],
            [$id],
        );

        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode($snapshot, $identity);

        $restored = $codec->decode(
            $artifact,
            $identity,
            $codec->fingerprint($artifact),
        );

        self::assertSame([$id], $restored->exportedServices);
        self::assertSame('base', $restored->ownerOf($id)?->value);
    }

    public function testUnknownExportIsRejectedByMergedSnapshot(): void
    {
        $this->expectException(InvalidModuleBoundaryException::class);

        new ModuleServiceDefinitions(
            new DefinitionBuilder()->build(),
            [],
            [],
            ['missing'],
        );
    }

    public function testChangingExportsChangesTheArtifactFingerprint(): void
    {
        $snapshot = new ModuleServiceCompiler()->compile([
            self::registration(),
        ]);

        $privateSnapshot = new ModuleServiceDefinitions(
            $snapshot->definitions,
            $snapshot->modules,
            [
                'entry:public' => new ModuleIdentifier('base'),
                'entry:private' => new ModuleIdentifier('base'),
            ],
        );

        $codec = new ModuleArtifactCodec();
        $identity = self::identity();

        self::assertNotSame(
            $codec->fingerprint($codec->encode($snapshot, $identity)),
            $codec->fingerprint($codec->encode($privateSnapshot, $identity)),
        );
    }

    #[DataProvider('invalidExports')]
    public function testInvalidExportRecordsAreRejected(mixed $exports): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();

        $artifact = $codec->encode(
            new ModuleServiceCompiler()->compile([self::registration()]),
            $identity,
        );

        $document = self::document($artifact);
        $document->exports = $exports;

        $modified = json_encode($document, JSON_THROW_ON_ERROR);

        $this->expectException(ModuleCacheException::class);

        // Recompute only to test structural validation independently.
        // Production callers must use a trusted expected fingerprint.
        $codec->decode(
            $modified,
            $identity,
            $codec->fingerprint($modified),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidExports(): iterable
    {
        yield 'not a list' => ['public'];
        yield 'object' => [new stdClass()];
        yield 'non-string entry' => [[17]];
        yield 'invalid base64' => [['%%%']];
        yield 'noncanonical base64' => [['cHVibGlj' . "\n"]];
        yield 'unknown service' => [[base64_encode('missing')]];
        yield 'empty service' => [['']];
        yield 'duplicate export' => [[
            base64_encode('public'),
            base64_encode('public'),
        ]];
    }

    public function testSchemaOneCannotSilentlyDiscardExports(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();

        $artifact = $codec->encode(
            new ModuleServiceCompiler()->compile([self::registration()]),
            $identity,
        );

        $document = self::document($artifact);
        $document->schema = 1;
        $modified = json_encode($document, JSON_THROW_ON_ERROR);

        $this->expectException(ModuleCacheException::class);

        $codec->decode(
            $modified,
            $identity,
            $codec->fingerprint($modified),
        );
    }

    public function testSchemaTwoRequiresExportsField(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();

        $artifact = $codec->encode(
            new ModuleServiceCompiler()->compile([self::registration()]),
            $identity,
        );

        $document = self::document($artifact);
        unset($document->exports);
        $modified = json_encode($document, JSON_THROW_ON_ERROR);

        $this->expectException(ModuleCacheException::class);

        $codec->decode(
            $modified,
            $identity,
            $codec->fingerprint($modified),
        );
    }

    private static function identity(): ModuleCacheIdentity
    {
        return new ModuleCacheIdentity('export-persistence-test', []);
    }

    private static function document(string $artifact): stdClass
    {
        $document = json_decode(
            $artifact,
            associative: false,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }

    private static function registration(): ModuleRegistration
    {
        $provider = new class () implements ServiceProviderInterface {
            public function register(ServiceRegistryInterface $registry): void
            {
                if (!$registry instanceof ExportingServiceRegistryInterface) {
                    throw new LogicException('Export support is required.');
                }

                $registry->value('public', 'ready');
                $registry->value('private', 'internal');
                $registry->export('public');
            }
        };

        return new ModuleRegistration(
            new ModuleDefinition(new ModuleIdentifier('base')),
            $provider,
        );
    }
}
