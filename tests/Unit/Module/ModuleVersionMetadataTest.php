<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Internal\ModuleMetadataCodec;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleVersion;
use Careminate\Module\ModuleVersionRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleVersionMetadataTest extends TestCase
{
    public function testLegacyMetadataRetainsItsCanonicalEncoding(): void
    {
        $json = '{"schema":1,"modules":[{"id":"base","required":[],'
            . '"optional":[],"requiredCapabilities":[],'
            . '"providedCapabilities":[]}]}';

        $codec = new ModuleMetadataCodec();
        $modules = $codec->decode($json);

        self::assertCount(1, $modules);
        self::assertNull($modules[0]->version);
        self::assertSame([], $modules[0]->dependencyVersions);
        self::assertSame($json, $codec->encode($modules));
    }

    #[DataProvider('ranges')]
    public function testVersionAndRangeRoundTrip(
        ModuleVersionRange $range,
    ): void {
        $codec = new ModuleMetadataCodec();

        $base = new ModuleDefinition(
            new ModuleIdentifier('base'),
            version: new ModuleVersion('1.5.0+build.001'),
        );

        $consumer = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            required: [new ModuleIdentifier('base')],
            dependencyVersions: ['base' => $range],
        );

        $json = $codec->encode([$consumer, $base]);
        $decoded = $codec->decode($json);

        self::assertStringStartsWith('{"schema":2,', $json);
        self::assertCount(2, $decoded);
        self::assertSame('base', $decoded[0]->id->value);
        self::assertSame('1.5.0+build.001', $decoded[0]->version?->value);
        self::assertNull($decoded[1]->version);

        $restored = $decoded[1]->dependencyVersions['base'];

        self::assertSame(
            $range->minimum?->value,
            $restored->minimum?->value,
        );
        self::assertSame(
            $range->maximum?->value,
            $restored->maximum?->value,
        );
        self::assertSame($range->includeMinimum, $restored->includeMinimum);
        self::assertSame($range->includeMaximum, $restored->includeMaximum);
        self::assertSame($json, $codec->encode($decoded));
    }

    /**
     * @return iterable<string, array{ModuleVersionRange}>
     */
    public static function ranges(): iterable
    {
        yield 'unbounded' => [ModuleVersionRange::any()];
        yield 'exact' => [
            ModuleVersionRange::exactly(new ModuleVersion('1.5.0')),
        ];
        yield 'minimum' => [
            ModuleVersionRange::atLeast(new ModuleVersion('1.0.0')),
        ];
        yield 'exclusive minimum' => [
            ModuleVersionRange::atLeast(
                new ModuleVersion('1.0.0'),
                inclusive: false,
            ),
        ];
        yield 'maximum' => [
            ModuleVersionRange::atMost(new ModuleVersion('2.0.0')),
        ];
        yield 'exclusive maximum' => [
            ModuleVersionRange::atMost(
                new ModuleVersion('2.0.0'),
                inclusive: false,
            ),
        ];
        yield 'bounded' => [
            ModuleVersionRange::between(
                new ModuleVersion('1.0.0'),
                new ModuleVersion('2.0.0-0'),
            ),
        ];
    }

    public function testRequirementOrderDoesNotAffectEncoding(): void
    {
        $first = ModuleVersionRange::atLeast(new ModuleVersion('1.0.0'));
        $second = ModuleVersionRange::atMost(new ModuleVersion('3.0.0'));

        $module = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            optional: [
                new ModuleIdentifier('alpha'),
                new ModuleIdentifier('zeta'),
            ],
            dependencyVersions: ['zeta' => $second, 'alpha' => $first],
        );

        $reordered = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            optional: [
                new ModuleIdentifier('zeta'),
                new ModuleIdentifier('alpha'),
            ],
            dependencyVersions: ['alpha' => $first, 'zeta' => $second],
        );

        $codec = new ModuleMetadataCodec();

        self::assertSame(
            $codec->encode([$module]),
            $codec->encode([$reordered]),
        );
    }

    public function testChangingAVersionChangesEncodedMetadata(): void
    {
        $codec = new ModuleMetadataCodec();

        self::assertNotSame(
            $codec->encode([
                new ModuleDefinition(
                    new ModuleIdentifier('base'),
                    version: new ModuleVersion('1.0.0'),
                ),
            ]),
            $codec->encode([
                new ModuleDefinition(
                    new ModuleIdentifier('base'),
                    version: new ModuleVersion('1.0.1'),
                ),
            ]),
        );
    }

    #[DataProvider('invalidRangeRecords')]
    public function testMalformedCachedRangesAreRejected(
        string $rangeJson,
    ): void {
        $json = '{"schema":2,"modules":[{'
            . '"id":"consumer","required":[],"optional":["base"],'
            . '"requiredCapabilities":[],"providedCapabilities":[],'
            . '"version":null,"dependencyVersions":['
            . $rangeJson
            . ']}]}';

        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRangeRecords(): iterable
    {
        $valid = '{"dependency":"base","minimum":"1.0.0",'
            . '"maximum":"2.0.0","includeMinimum":true,'
            . '"includeMaximum":false}';

        yield 'duplicate dependency' => [$valid . ',' . $valid];
        yield 'undeclared dependency' => [
            str_replace('"base"', '"missing"', $valid),
        ];
        yield 'nonboolean flag' => [
            str_replace('"includeMinimum":true', '"includeMinimum":1', $valid),
        ];
        yield 'inclusive absent bound' => [
            str_replace('"minimum":"1.0.0"', '"minimum":null', $valid),
        ];
        yield 'invalid version' => [
            str_replace('"minimum":"1.0.0"', '"minimum":"01.0.0"', $valid),
        ];
        yield 'reversed bounds' => [
            str_replace('"minimum":"1.0.0"', '"minimum":"3.0.0"', $valid),
        ];
        yield 'empty interval' => [
            str_replace('"minimum":"1.0.0"', '"minimum":"2.0.0"', $valid),
        ];
        yield 'unexpected field' => [
            substr($valid, 0, -1) . ',"extra":true}',
        ];
    }

    public function testSchemaOneCannotSilentlyDiscardVersionFields(): void
    {
        $json = '{"schema":1,"modules":[{'
            . '"id":"base","required":[],"optional":[],'
            . '"requiredCapabilities":[],"providedCapabilities":[],'
            . '"version":"1.0.0","dependencyVersions":[]}]}';

        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode($json);
    }

    public function testSchemaTwoRequiresVersionFields(): void
    {
        $json = '{"schema":2,"modules":[{'
            . '"id":"base","required":[],"optional":[],'
            . '"requiredCapabilities":[],"providedCapabilities":[]}]}';

        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode($json);
    }
}
