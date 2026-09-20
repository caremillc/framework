<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Internal\ModuleArtifactCodec;
use Careminate\Module\Internal\ModuleCacheIdentity;
use Careminate\Module\Internal\ModuleMetadataCodec;
use Careminate\Module\Internal\ModuleOwnershipCodec;
use Careminate\Module\Internal\ModuleOwnershipManifest;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use PHPUnit\Framework\TestCase;

final class ModuleArtifactCodecTest extends TestCase
{
    public function testRoundTripPreservesServicesModulesAndOwnership(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $decoded = $codec->decode(
            $artifact,
            $identity,
            $codec->fingerprint($artifact),
        );

        self::assertCount(1, $decoded->modules);
        self::assertSame('billing', $decoded->modules[0]->id->value);

        $owner = $decoded->ownerOf('currency');

        self::assertInstanceOf(ModuleIdentifier::class, $owner);
        self::assertSame('billing', $owner->value);
        self::assertNull($decoded->ownerOf('missing'));

        $container = new CompiledDefinitionContainerFactory()->create(
            $decoded->definitions,
        );

        self::assertTrue($container->isFrozen());
        self::assertSame('USD', $container->get('currency'));
        self::assertSame($artifact, $codec->encode($decoded, $identity));
    }

    public function testAlteredBytesFailBeforeJsonParsing(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'The module artifact fingerprint does not match.',
        );

        $codec->decode(
            '{',
            $identity,
            $codec->fingerprint($artifact),
        );
    }

    public function testDifferentBuildIdentityIsRejected(): void
    {
        $codec = new ModuleArtifactCodec();
        $artifact = $codec->encode(self::snapshot(), self::identity());

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'The module artifact cache identity does not match.',
        );

        $codec->decode(
            $artifact,
            self::identity('build-2'),
            $codec->fingerprint($artifact),
        );
    }

    public function testChangedDisabledSelectionIsRejected(): void
    {
        $codec = new ModuleArtifactCodec();
        $artifact = $codec->encode(self::snapshot(), self::identity());

        $changed = new ModuleCacheIdentity(
            buildId: 'build-1',
            sourceFingerprints: [],
            disabled: [new ModuleIdentifier('billing')],
            phpVersion: '8.5.8',
        );

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'The module artifact cache identity does not match.',
        );

        $codec->decode(
            $artifact,
            $changed,
            $codec->fingerprint($artifact),
        );
    }

    public function testOwnershipMustNameTheSameModulesAsMetadata(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $other = new ModuleIdentifier('catalog');
        $ownership = new ModuleOwnershipManifest(
            [$other],
            [['id' => 'currency', 'owner' => $other]],
        );

        $changed = self::replaceSection(
            $artifact,
            'ownership',
            new ModuleOwnershipCodec()->encode($ownership),
        );

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'Artifact metadata and ownership name different modules.',
        );

        $codec->decode($changed, $identity, $codec->fingerprint($changed));
    }

    public function testSameCountWithDifferentServiceOwnershipIsRejected(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $owner = new ModuleIdentifier('billing');
        $ownership = new ModuleOwnershipManifest(
            [$owner],
            [['id' => 'different', 'owner' => $owner]],
        );

        $changed = self::replaceSection(
            $artifact,
            'ownership',
            new ModuleOwnershipCodec()->encode($ownership),
        );

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'A cached service has no matching ownership declaration.',
        );

        $codec->decode($changed, $identity, $codec->fingerprint($changed));
    }

    public function testExtraOwnershipDeclarationsAreRejected(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $owner = new ModuleIdentifier('billing');
        $ownership = new ModuleOwnershipManifest(
            [$owner],
            [
                ['id' => 'currency', 'owner' => $owner],
                ['id' => 'extra', 'owner' => $owner],
            ],
        );

        $changed = self::replaceSection(
            $artifact,
            'ownership',
            new ModuleOwnershipCodec()->encode($ownership),
        );

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'Artifact services and ownership declarations do not match.',
        );

        $codec->decode($changed, $identity, $codec->fingerprint($changed));
    }

    public function testServicePayloadIntegrityIsAlsoChecked(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $changed = self::replaceSection(
            $artifact,
            'definitions',
            'invalid definition artifact',
        );

        $this->expectException(ModuleCacheException::class);

        $codec->decode($changed, $identity, $codec->fingerprint($changed));
    }

    public function testMetadataSectionCannotDisagreeWithOwnership(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();
        $artifact = $codec->encode(self::snapshot(), $identity);

        $changed = self::replaceSection(
            $artifact,
            'metadata',
            new ModuleMetadataCodec()->encode([]),
        );

        $this->expectException(ModuleCacheException::class);

        $codec->decode($changed, $identity, $codec->fingerprint($changed));
    }

    public function testAliasesAreRejectedUntilOwnershipIsSupported(): void
    {
        $owner = new ModuleIdentifier('billing');
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('currency', 'USD'));
        $builder->alias('alias', 'currency');

        $snapshot = new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition($owner)],
            ['entry:currency' => $owner],
        );

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'This module artifact version supports owned services only.',
        );

        new ModuleArtifactCodec()->encode($snapshot, self::identity());
    }

    public function testEmptySnapshotRoundTrips(): void
    {
        $codec = new ModuleArtifactCodec();
        $identity = self::identity();

        $snapshot = new ModuleServiceDefinitions(
            new DefinitionBuilder()->build(),
            [],
            [],
        );

        $artifact = $codec->encode($snapshot, $identity);
        $decoded = $codec->decode(
            $artifact,
            $identity,
            $codec->fingerprint($artifact),
        );

        self::assertSame([], $decoded->modules);
        self::assertSame([], $decoded->definitions->services);
    }

    private static function identity(
        string $buildId = 'build-1',
    ): ModuleCacheIdentity {
        return new ModuleCacheIdentity(
            $buildId,
            [],
            phpVersion: '8.5.8',
        );
    }

    private static function snapshot(): ModuleServiceDefinitions
    {
        $owner = new ModuleIdentifier('billing');
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('currency', 'USD'));

        return new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition($owner)],
            ['entry:currency' => $owner],
        );
    }

    private static function replaceSection(
        string $artifact,
        string $section,
        string $replacement,
    ): string {
        $document = json_decode($artifact, true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($document);

        $document[$section] = $replacement;

        return json_encode($document, JSON_THROW_ON_ERROR);
    }
}
