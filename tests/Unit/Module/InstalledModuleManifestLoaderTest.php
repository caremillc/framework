<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use ArrayObject;
use Careminate\Module\Exception\ModuleDiscoveryException;
use Careminate\Module\Internal\InstalledModuleManifestLoader;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstalledModuleManifestLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-manifest-'
            . bin2hex(random_bytes(12));

        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('The test directory could not be created.');
        }
    }

    protected function tearDown(): void
    {
        $manifest = $this->directory . DIRECTORY_SEPARATOR . 'composer.json';

        if (is_file($manifest) && !unlink($manifest)) {
            throw new RuntimeException('The test manifest could not be removed.');
        }

        if (!rmdir($this->directory)) {
            throw new RuntimeException('The test directory could not be removed.');
        }
    }

    public function testSelectedManifestProducesPackageOwnedEntries(): void
    {
        $this->writeManifest(
            '{"name":"vendor/package","extra":{"careminate":'
            . '{"modules":["Example\\\\BillingModule"]}}}',
        );

        $entries = $this->loader()->load(['vendor/package']);

        self::assertCount(1, $entries);
        self::assertSame('vendor/package', $entries[0]->package);
        self::assertSame('Example\\BillingModule', $entries[0]->className);
    }

    public function testRepeatedSelectionReadsThePackageOnce(): void
    {
        $this->writeManifest('{"name":"vendor/package"}');

        /** @var ArrayObject<int, string> $lookups */
        $lookups = new ArrayObject();

        $directory = $this->directory;

        $loader = new InstalledModuleManifestLoader(
            static function (string $package) use (
                $lookups,
                $directory,
            ): string {
                $lookups->append($package);

                return $directory;
            },
        );

        self::assertSame(
            [],
            $loader->load(['vendor/package', 'vendor/package']),
        );
        self::assertSame(['vendor/package'], $lookups->getArrayCopy());
    }

    public function testEmptySelectionDoesNotPerformLookup(): void
    {
        $loader = new InstalledModuleManifestLoader(
            static function (string $package): ?string {
                self::fail('Empty selection must not query installed packages.');
            },
        );

        self::assertSame([], $loader->load([]));
    }

    public function testMissingInstallPathIsRejected(): void
    {
        $loader = new InstalledModuleManifestLoader(
            static fn (string $package): ?string => null,
        );

        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A selected package has no usable installation directory.',
        );

        $loader->load(['vendor/package']);
    }

    public function testLookupFailurePreservesItsCause(): void
    {
        $original = new OutOfBoundsException('Package is not installed.');

        $loader = new InstalledModuleManifestLoader(
            static function (string $package) use ($original): never {
                throw $original;
            },
        );

        try {
            $loader->load(['vendor/package']);
        } catch (ModuleDiscoveryException $exception) {
            self::assertSame($original, $exception->getPrevious());

            return;
        }

        self::fail('An install-path lookup failure must propagate.');
    }

    public function testMissingManifestIsRejected(): void
    {
        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A selected package manifest must be a regular non-symlink file.',
        );

        $this->loader()->load(['vendor/package']);
    }

    public function testOversizedManifestIsRejectedBeforeParsing(): void
    {
        $this->writeManifest(str_repeat(' ', 1048577));

        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A selected package manifest exceeds the size limit.',
        );

        $this->loader()->load(['vendor/package']);
    }

    public function testManifestPackageIdentityIsChecked(): void
    {
        $this->writeManifest('{"name":"vendor/different"}');

        $this->expectException(ModuleDiscoveryException::class);
        $this->expectExceptionMessage(
            'A module manifest does not match its selected package.',
        );

        $this->loader()->load(['vendor/package']);
    }

    public function testDefaultLookupCanReadAnInstalledDependency(): void
    {
        // psr/container is already a required framework dependency.
        self::assertSame(
            [],
            new InstalledModuleManifestLoader()->load(['psr/container']),
        );
    }

    private function loader(): InstalledModuleManifestLoader
    {
        $directory = $this->directory;

        return new InstalledModuleManifestLoader(
            static fn (string $package): string => $directory,
        );
    }

    private function writeManifest(string $contents): void
    {
        $written = file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'composer.json',
            $contents,
        );

        if ($written !== strlen($contents)) {
            throw new RuntimeException('The test manifest could not be written.');
        }
    }
}
