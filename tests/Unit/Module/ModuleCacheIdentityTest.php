<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\Internal\ModuleCacheIdentity;
use Careminate\Module\ModuleIdentifier;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleCacheIdentityTest extends TestCase
{
    public function testEquivalentInputSetsHaveTheSameIdentity(): void
    {
        $first = new ModuleCacheIdentity(
            buildId: 'build-1',
            sourceFingerprints: [
                'module:billing' => hash('sha256', 'billing'),
                'manifest:vendor/search' => hash('sha256', 'search'),
            ],
            selectedPackages: ['vendor/search', 'vendor/billing'],
            disabled: [
                new ModuleIdentifier('search'),
                new ModuleIdentifier('audit'),
            ],
            phpVersion: '8.5.8',
        );

        $second = new ModuleCacheIdentity(
            buildId: 'build-1',
            sourceFingerprints: [
                'manifest:vendor/search' => hash('sha256', 'search'),
                'module:billing' => hash('sha256', 'billing'),
            ],
            selectedPackages: [
                'vendor/billing',
                'vendor/search',
                'vendor/search',
            ],
            disabled: [
                new ModuleIdentifier('audit'),
                new ModuleIdentifier('search'),
                new ModuleIdentifier('audit'),
            ],
            phpVersion: '8.5.8',
        );

        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $first->fingerprint(),
        );
    }

    #[DataProvider('changedInputs')]
    public function testMaterialInputChangesInvalidateTheIdentity(
        string $buildId,
        string $sourceName,
        string $sourceContents,
        string $package,
        string $disabled,
        string $phpVersion,
    ): void {
        $baseline = new ModuleCacheIdentity(
            buildId: 'build-1',
            sourceFingerprints: ['module:billing' => hash('sha256', 'original')],
            selectedPackages: ['vendor/billing'],
            disabled: [new ModuleIdentifier('search')],
            phpVersion: '8.5.8',
        );

        $changed = new ModuleCacheIdentity(
            buildId: $buildId,
            sourceFingerprints: [$sourceName => hash('sha256', $sourceContents)],
            selectedPackages: [$package],
            disabled: [new ModuleIdentifier($disabled)],
            phpVersion: $phpVersion,
        );

        self::assertNotSame(
            $baseline->fingerprint(),
            $changed->fingerprint(),
        );
    }

    public function testRemovingASourceInvalidatesTheIdentity(): void
    {
        $withSource = new ModuleCacheIdentity(
            'build-1',
            ['module:billing' => hash('sha256', 'source')],
        );

        $withoutSource = new ModuleCacheIdentity('build-1', []);

        self::assertNotSame(
            $withSource->fingerprint(),
            $withoutSource->fingerprint(),
        );
    }

    public function testDefaultPhpVersionMatchesTheCurrentRuntime(): void
    {
        $default = new ModuleCacheIdentity('build-1', []);
        $explicit = new ModuleCacheIdentity(
            'build-1',
            [],
            phpVersion: PHP_VERSION,
        );

        self::assertSame($default->fingerprint(), $explicit->fingerprint());
    }

    #[DataProvider('invalidDigests')]
    public function testInvalidSourceDigestsAreRejected(string $digest): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleCacheIdentity(
            'build-1',
            ['module:billing' => $digest],
        );
    }

    public function testEmptyBuildIdentifierIsRejected(): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleCacheIdentity('', []);
    }

    public function testControlCharactersInSourceNamesAreRejected(): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleCacheIdentity(
            'build-1',
            ["module:\nbilling" => hash('sha256', 'source')],
        );
    }

    public function testEmptySelectedPackageIsRejected(): void
    {
        $this->expectException(ModuleCacheException::class);

        new ModuleCacheIdentity('build-1', [], selectedPackages: ['']);
    }

    public function testInvalidUtf8PreservesTheEncodingFailure(): void
    {
        try {
            new ModuleCacheIdentity("\xFF", []);
        } catch (ModuleCacheException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());

            return;
        }

        self::fail('Invalid UTF-8 must fail identity encoding.');
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function changedInputs(): iterable
    {
        yield 'build' => [
            'build-2', 'module:billing', 'original',
            'vendor/billing', 'search', '8.5.8',
        ];

        yield 'source identity' => [
            'build-1', 'module:catalog', 'original',
            'vendor/billing', 'search', '8.5.8',
        ];

        yield 'source content' => [
            'build-1', 'module:billing', 'changed',
            'vendor/billing', 'search', '8.5.8',
        ];

        yield 'package selection' => [
            'build-1', 'module:billing', 'original',
            'vendor/catalog', 'search', '8.5.8',
        ];

        yield 'disabled module' => [
            'build-1', 'module:billing', 'original',
            'vendor/billing', 'audit', '8.5.8',
        ];

        yield 'PHP runtime' => [
            'build-1', 'module:billing', 'original',
            'vendor/billing', 'search', '8.4.0',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDigests(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'non hexadecimal' => [str_repeat('g', 64)];
    }
}
