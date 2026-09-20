<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Internal\ModuleOwnershipCodec;
use Careminate\Module\Internal\ModuleOwnershipManifest;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleOwnershipCodecTest extends TestCase
{
    public function testRoundTripPreservesBinaryAndNumericServiceIdentifiers(): void
    {
        $owner = new ModuleIdentifier('billing');

        $manifest = new ModuleOwnershipManifest(
            [$owner],
            [
                ['id' => "\xFF\0service", 'owner' => $owner],
                ['id' => '01', 'owner' => $owner],
                ['id' => '1', 'owner' => $owner],
            ],
        );

        $codec = new ModuleOwnershipCodec();
        $encoded = $codec->encode($manifest);
        $decoded = $codec->decode($encoded);

        foreach (["\xFF\0service", '01', '1'] as $id) {
            $decodedOwner = $decoded->ownerOf($id);

            self::assertInstanceOf(ModuleIdentifier::class, $decodedOwner);
            self::assertSame('billing', $decodedOwner->value);
        }

        self::assertNull($decoded->ownerOf('missing'));
        self::assertSame($encoded, $codec->encode($decoded));
    }

    public function testEquivalentManifestsHaveIdenticalEncoding(): void
    {
        $alpha = new ModuleIdentifier('alpha');
        $beta = new ModuleIdentifier('beta');

        $first = new ModuleOwnershipManifest(
            [$beta, $alpha],
            [
                ['id' => 'second', 'owner' => $beta],
                ['id' => 'first', 'owner' => $alpha],
            ],
        );

        $second = new ModuleOwnershipManifest(
            [$alpha, $beta],
            [
                ['id' => 'first', 'owner' => $alpha],
                ['id' => 'second', 'owner' => $beta],
            ],
        );

        $codec = new ModuleOwnershipCodec();

        self::assertSame($codec->encode($first), $codec->encode($second));
    }

    public function testEmptyManifestRoundTrips(): void
    {
        $codec = new ModuleOwnershipCodec();
        $decoded = $codec->decode(
            $codec->encode(new ModuleOwnershipManifest([], [])),
        );

        self::assertSame([], $decoded->modules);
        self::assertSame([], $decoded->services);
    }

    public function testExtractionRequiresEveryServiceToHaveAnOwner(): void
    {
        $owner = new ModuleIdentifier('billing');
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('service', null));

        $definitions = new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition($owner)],
            [],
        );

        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'A contributed service has no recorded owner.',
        );

        ModuleOwnershipManifest::fromDefinitions($definitions);
    }

    public function testExtractionPreservesRecordedOwnership(): void
    {
        $owner = new ModuleIdentifier('billing');
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('service', null));

        $definitions = new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition($owner)],
            ['entry:service' => $owner],
        );

        $manifest = ModuleOwnershipManifest::fromDefinitions($definitions);

        self::assertSame($owner, $manifest->ownerOf('service'));
        self::assertCount(1, $manifest->services);
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsAreRejected(string $json): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleOwnershipCodec()->decode($json);
    }

    public function testMalformedJsonPreservesItsCause(): void
    {
        try {
            new ModuleOwnershipCodec()->decode('{');
        } catch (ModuleCacheException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());

            return;
        }

        self::fail('Malformed ownership JSON must fail.');
    }

    public function testOversizedInputIsRejected(): void
    {
        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage(
            'Module ownership metadata exceeds the size limit.',
        );

        new ModuleOwnershipCodec()->decode(str_repeat(' ', 1048577));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'array root' => ['[]'];
        yield 'unsupported schema' => [
            '{"schema":2,"modules":[],"services":[]}',
        ];
        yield 'extra field' => [
            '{"schema":1,"modules":[],"services":[],"extra":true}',
        ];
        yield 'object module list' => [
            '{"schema":1,"modules":{},"services":[]}',
        ];
        yield 'invalid module identifier' => [
            '{"schema":1,"modules":["Billing"],"services":[]}',
        ];
        yield 'duplicate module' => [
            '{"schema":1,"modules":["billing","billing"],"services":[]}',
        ];
        yield 'unknown owner' => [
            '{"schema":1,"modules":[],"services":'
            . '[{"id":"c2VydmljZQ==","owner":"billing"}]}',
        ];
        yield 'empty service identifier' => [
            '{"schema":1,"modules":["billing"],"services":'
            . '[{"id":"","owner":"billing"}]}',
        ];
        yield 'invalid base64' => [
            '{"schema":1,"modules":["billing"],"services":'
            . '[{"id":"***","owner":"billing"}]}',
        ];
        yield 'non canonical base64' => [
            '{"schema":1,"modules":["billing"],"services":'
            . '[{"id":"c2VydmljZQ","owner":"billing"}]}',
        ];
        yield 'duplicate service' => [
            '{"schema":1,"modules":["billing"],"services":'
            . '[{"id":"c2VydmljZQ==","owner":"billing"},'
            . '{"id":"c2VydmljZQ==","owner":"billing"}]}',
        ];
    }
}
