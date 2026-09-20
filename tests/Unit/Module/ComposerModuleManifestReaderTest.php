<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\ModuleDiscoveryException;
use Careminate\Module\Internal\ComposerModuleManifestReader;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerModuleManifestReaderTest extends TestCase
{
    public function testPackagesAreSortedAndDeclarationOrderIsPreserved(): void
    {
        $entries = new ComposerModuleManifestReader()->read([
            'vendor/zeta' => self::manifest(
                'vendor/zeta',
                ['Example\\ZetaModule'],
            ),
            'vendor/alpha' => self::manifest(
                'vendor/alpha',
                ['Example\\SecondModule', 'Example\\FirstModule'],
            ),
        ]);

        self::assertCount(3, $entries);
        self::assertSame('vendor/alpha', $entries[0]->package);
        self::assertSame('Example\\SecondModule', $entries[0]->className);
        self::assertSame('Example\\FirstModule', $entries[1]->className);
        self::assertSame('vendor/zeta', $entries[2]->package);
        self::assertSame('Example\\ZetaModule', $entries[2]->className);
    }

    public function testPackagesWithoutDeclarationsAreIgnored(): void
    {
        $entries = new ComposerModuleManifestReader()->read([
            'vendor/one' => '{"name":"vendor/one"}',
            'vendor/two' => '{"name":"vendor/two","extra":{"other":true}}',
            'vendor/three' => self::manifest('vendor/three', []),
        ]);

        self::assertSame([], $entries);
    }

    public function testEmptySelectionProducesNoEntries(): void
    {
        self::assertSame([], new ComposerModuleManifestReader()->read([]));
    }

    public function testManifestNameMustMatchTheSelectedPackage(): void
    {
        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A module manifest does not match its selected package.',
        );

        new ComposerModuleManifestReader()->read([
            'vendor/selected' => self::manifest('vendor/different', []),
        ]);
    }

    public function testDuplicateClassesAreRejectedAcrossPackages(): void
    {
        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A module entry-point class is declared more than once.',
        );

        new ComposerModuleManifestReader()->read([
            'vendor/one' => self::manifest(
                'vendor/one',
                ['Example\\BillingModule'],
            ),
            'vendor/two' => self::manifest(
                'vendor/two',
                ['example\\billingmodule'],
            ),
        ]);
    }

    public function testDuplicateClassesAreRejectedWithinAPackage(): void
    {
        $this->expectException(ModuleDiscoveryException::class);

        new ComposerModuleManifestReader()->read([
            'vendor/package' => self::manifest(
                'vendor/package',
                ['Example\\Module', 'Example\\Module'],
            ),
        ]);
    }

    public function testJsonFailurePreservesItsCause(): void
    {
        try {
            new ComposerModuleManifestReader()->read([
                'vendor/package' => '{',
            ]);
        } catch (ModuleDiscoveryException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());

            return;
        }

        self::fail('Malformed JSON must fail.');
    }

    public function testOversizedManifestIsRejected(): void
    {
        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A selected package manifest exceeds the size limit.',
        );

        new ComposerModuleManifestReader()->read([
            'vendor/package' => str_repeat(' ', 1048577),
        ]);
    }

    #[DataProvider('invalidMetadata')]
    public function testInvalidMetadataIsRejected(string $json): void
    {
        $this->expectException(ModuleDiscoveryException::class);

        new ComposerModuleManifestReader()->read([
            'vendor/package' => $json,
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMetadata(): iterable
    {
        yield 'array root' => ['[]'];
        yield 'missing package name' => ['{}'];
        yield 'null extra' => [
            '{"name":"vendor/package","extra":null}',
        ];
        yield 'array extra' => [
            '{"name":"vendor/package","extra":[]}',
        ];
        yield 'null careminate metadata' => [
            '{"name":"vendor/package","extra":{"careminate":null}}',
        ];
        yield 'missing modules' => [
            '{"name":"vendor/package","extra":{"careminate":{}}}',
        ];
        yield 'object modules' => [
            '{"name":"vendor/package","extra":{"careminate":{"modules":{}}}}',
        ];
        yield 'non string entry' => [
            '{"name":"vendor/package","extra":{"careminate":{"modules":[1]}}}',
        ];
        yield 'empty class' => [
            self::manifest('vendor/package', ['']),
        ];
        yield 'leading namespace separator' => [
            self::manifest('vendor/package', ['\\Example\\Module']),
        ];
        yield 'empty namespace segment' => [
            self::manifest('vendor/package', ['Example\\\\Module']),
        ];
        yield 'path instead of class' => [
            self::manifest('vendor/package', ['Example/Module.php']),
        ];
        yield 'control character' => [
            self::manifest('vendor/package', ["Example\\Module\n"]),
        ];
    }

    /**
     * @param list<string> $classes
     */
    private static function manifest(string $package, array $classes): string
    {
        return json_encode(
            [
                'name' => $package,
                'extra' => [
                    'careminate' => [
                        'modules' => $classes,
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
