<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Discovery\ComposerModuleDiscovery;
use PHPUnit\Framework\TestCase;

final class ComposerModuleDiscoveryTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-module-test-'
            . bin2hex(random_bytes(6));

        mkdir(
            $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'composer',
            0775,
            true,
        );
    }

    protected function tearDown(): void
    {
        $installed = $this->temporaryDirectory
            . DIRECTORY_SEPARATOR
            . 'composer'
            . DIRECTORY_SEPARATOR
            . 'installed.json';

        if (is_file($installed)) {
            unlink($installed);
        }

        $composerDirectory = dirname($installed);

        if (is_dir($composerDirectory)) {
            rmdir($composerDirectory);
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    public function test_it_discovers_modules_from_composer_extra(): void
    {
        $path = $this->temporaryDirectory
            . DIRECTORY_SEPARATOR
            . 'composer'
            . DIRECTORY_SEPARATOR
            . 'installed.json';

        file_put_contents($path, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/example',
                    'extra' => [
                        'careminate' => [
                            'modules' => [
                                'Vendor\\Example\\ExampleModule',
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $modules = iterator_to_array(
            (function () use ($path): iterable {
                yield from (new ComposerModuleDiscovery($path))->discover();
            })(),
            false,
        );

        self::assertSame(
            ['Vendor\\Example\\ExampleModule'],
            $modules,
        );
    }
}
