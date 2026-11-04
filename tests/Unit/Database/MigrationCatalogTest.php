<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Database;

use Careminate\Database\Migrations\MigrationCatalog;
use Careminate\Database\Migrations\MigrationException;
use PHPUnit\Framework\TestCase;

final class MigrationCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/careminate-migrations-'
            . bin2hex(random_bytes(8));

        self::assertTrue(
            mkdir($this->directory, 0o700),
        );
    }

    protected function tearDown(): void
    {
        $paths = glob($this->directory . '/*');

        if (is_array($paths)) {
            foreach ($paths as $path) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    public function testEmptyCatalogIsValid(): void
    {
        self::assertSame(
            [],
            new MigrationCatalog(
                $this->directory,
            )->discover(),
        );
    }

    public function testMigrationsSortByFileNameAndCaptureChecksums(): void
    {
        $later = $this->directory
            . '/20261104_020000_second.php';

        $earlier = $this->directory
            . '/20261104_010000_first.php';

        file_put_contents(
            $later,
            '<?php return static function (): void {};',
        );

        file_put_contents(
            $earlier,
            '<?php return static function (): void {};',
        );

        $catalog =
            new MigrationCatalog($this->directory)
        ->discover();

        self::assertSame(
            [
                '20261104_010000_first',
                '20261104_020000_second',
            ],
            array_keys($catalog),
        );

        self::assertSame(
            hash_file('sha256', $earlier),
            $catalog['20261104_010000_first']['checksum'],
        );
    }

    public function testRejectsMalformedMigrationName(): void
    {
        file_put_contents(
            $this->directory . '/migration-one.php',
            '<?php',
        );

        $this->expectException(
            MigrationException::class,
        );

        new MigrationCatalog(
            $this->directory,
        )->discover();
    }

    public function testMissingDirectoryFails(): void
    {
        $this->expectException(
            MigrationException::class,
        );

        new MigrationCatalog(
            $this->directory . '/missing',
        )->discover();
    }
}
