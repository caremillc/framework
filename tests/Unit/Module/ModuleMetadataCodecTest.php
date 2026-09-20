<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\CapabilityIdentifier;
use Careminate\Module\CapabilityProvision;
use Careminate\Module\Exception\CapabilityResolutionException;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\Internal\ModuleMetadataCodec;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleMetadataCodecTest extends TestCase
{
    public function testRoundTripPreservesDependenciesAndCapabilities(): void
    {
        $storage = new ModuleDefinition(
            new ModuleIdentifier('storage'),
            providedCapabilities: [
                new CapabilityProvision(
                    new CapabilityIdentifier('files'),
                    exclusive: true,
                ),
            ],
        );

        $billing = new ModuleDefinition(
            new ModuleIdentifier('billing'),
            required: [new ModuleIdentifier('storage')],
            optional: [new ModuleIdentifier('audit')],
            requiredCapabilities: [new CapabilityIdentifier('files')],
        );

        $codec = new ModuleMetadataCodec();
        $encoded = $codec->encode([$billing, $storage]);
        $decoded = $codec->decode($encoded);

        self::assertCount(2, $decoded);
        self::assertSame('storage', $decoded[0]->id->value);
        self::assertSame('billing', $decoded[1]->id->value);
        self::assertSame('storage', $decoded[1]->required[0]->value);
        self::assertSame('audit', $decoded[1]->optional[0]->value);
        self::assertSame('files', $decoded[1]->requiredCapabilities[0]->value);
        self::assertTrue($decoded[0]->providedCapabilities[0]->exclusive);
        self::assertSame($encoded, $codec->encode($decoded));
    }

    public function testEquivalentDeclarationOrdersEncodeIdentically(): void
    {
        $alpha = new ModuleIdentifier('alpha');
        $beta = new ModuleIdentifier('beta');

        $first = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            optional: [$beta, $alpha],
        );

        $second = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            optional: [$alpha, $beta],
        );

        $codec = new ModuleMetadataCodec();

        self::assertSame($codec->encode([$first]), $codec->encode([$second]));
    }

    public function testDecodeDerivesDependencyOrderFromTheGraph(): void
    {
        $json = self::document([
            self::record('billing', required: ['storage']),
            self::record('storage'),
        ]);

        $decoded = new ModuleMetadataCodec()->decode($json);

        self::assertSame('storage', $decoded[0]->id->value);
        self::assertSame('billing', $decoded[1]->id->value);
    }

    public function testEncodeRejectsMissingRequiredModules(): void
    {
        $module = new ModuleDefinition(
            new ModuleIdentifier('billing'),
            required: [new ModuleIdentifier('missing')],
        );

        try {
            new ModuleMetadataCodec()->encode([$module]);
        } catch (ModuleCacheException $exception) {
            self::assertInstanceOf(
                ModuleResolutionException::class,
                $exception->getPrevious(),
            );

            return;
        }

        self::fail('Invalid relationships must not be encoded.');
    }

    public function testDecodeRejectsUnsatisfiedCapabilities(): void
    {
        $json = self::document([
            self::record('billing', capabilities: ['files']),
        ]);

        try {
            new ModuleMetadataCodec()->decode($json);
        } catch (ModuleCacheException $exception) {
            self::assertInstanceOf(
                CapabilityResolutionException::class,
                $exception->getPrevious(),
            );

            return;
        }

        self::fail('Unsatisfied capabilities must not be decoded.');
    }

    public function testDecodeRejectsDependencyCycles(): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode(self::document([
            self::record('alpha', required: ['beta']),
            self::record('beta', required: ['alpha']),
        ]));
    }

    public function testDecodeRejectsDuplicateModuleIdentifiers(): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode(self::document([
            self::record('billing'),
            self::record('billing'),
        ]));
    }

    public function testDecodeRejectsExclusiveCapabilityConflicts(): void
    {
        $provision = [['name' => 'files', 'exclusive' => true]];

        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode(self::document([
            self::record('alpha', provided: $provision),
            self::record('beta', provided: $provision),
        ]));
    }

    public function testEmptySnapshotRoundTrips(): void
    {
        $codec = new ModuleMetadataCodec();

        self::assertSame([], $codec->decode($codec->encode([])));
    }

    public function testInvalidJsonPreservesItsCause(): void
    {
        try {
            new ModuleMetadataCodec()->decode('{');
        } catch (ModuleCacheException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());

            return;
        }

        self::fail('Malformed JSON must fail.');
    }

    #[DataProvider('invalidDocuments')]
    public function testMalformedStructuresAreRejected(string $json): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleMetadataCodec()->decode($json);
    }

    public function testOversizedInputIsRejected(): void
    {
        $this->expectException(ModuleCacheException::class);
        $this->expectExceptionMessage('Module metadata exceeds the size limit.');

        new ModuleMetadataCodec()->decode(str_repeat(' ', 1048577));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'array root' => ['[]'];
        // yield 'unknown schema' => ['{"schema":2,"modules":[]}'];
        yield 'unknown schema' => ['{"schema":999,"modules":[]}'];
        yield 'string schema' => ['{"schema":"1","modules":[]}'];
        yield 'object modules' => ['{"schema":1,"modules":{}}'];
        yield 'extra field' => ['{"schema":1,"modules":[],"extra":true}'];
        yield 'missing record fields' => [
            '{"schema":1,"modules":[{"id":"billing"}]}',
        ];
        yield 'invalid identifier' => [
            self::document([self::record('Billing')]),
        ];
        yield 'non boolean exclusivity' => [
            self::document([
                self::record(
                    'billing',
                    provided: [['name' => 'files', 'exclusive' => 'true']],
                ),
            ]),
        ];
    }

    /**
     * @param list<string> $required
     * @param list<string> $capabilities
     * @param list<array<string, mixed>> $provided
     *
     * @return array<string, mixed>
     */
    private static function record(
        string $id,
        array $required = [],
        array $capabilities = [],
        array $provided = [],
    ): array {
        return [
            'id' => $id,
            'required' => $required,
            'optional' => [],
            'requiredCapabilities' => $capabilities,
            'providedCapabilities' => $provided,
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private static function document(array $records): string
    {
        return json_encode(
            ['schema' => 1, 'modules' => $records],
            JSON_THROW_ON_ERROR,
        );
    }
}
