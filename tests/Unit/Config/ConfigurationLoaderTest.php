<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Config;

use Careminate\Config\ConfigurationLoader;
use Careminate\Config\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'careminate-config-');

        if ($path === false) {
            self::fail('Unable to create temporary configuration path.');
        }

        unlink($path);

        if (!mkdir($path, 0o700)) {
            self::fail('Unable to create temporary configuration directory.');
        }

        $this->directory = $path;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function testLoadsNamespacedConfiguration(): void
    {
        $this->write(
            'app.php',
            '<?php return ["name" => "Careminate", "debug" => false];'
        );

        $config = new ConfigurationLoader()->load(
            $this->directory,
            new Environment()
        );

        self::assertSame('Careminate', $config->string('app.name'));
        self::assertFalse($config->boolean('app.debug'));
    }

    public function testEnvironmentIsAvailableToConfigurationFile(): void
    {
        $this->write(
            'app.php',
            '<?php return ["debug" => $environment->boolean("APP_DEBUG")];'
        );

        $config = new ConfigurationLoader()->load(
            $this->directory,
            new Environment(['APP_DEBUG' => 'true'])
        );

        self::assertTrue($config->boolean('app.debug'));
    }

    public function testLoadsMultipleNamespaces(): void
    {
        $this->write('database.php', '<?php return ["driver" => "mysql"];');
        $this->write('app.php', '<?php return ["name" => "Careminate"];');

        $config = new ConfigurationLoader()->load(
            $this->directory,
            new Environment()
        );

        self::assertSame('Careminate', $config->string('app.name'));
        self::assertSame('mysql', $config->string('database.driver'));
    }

    public function testEmptyDirectoryReturnsEmptyConfiguration(): void
    {
        $config = new ConfigurationLoader()->load(
            $this->directory,
            new Environment()
        );

        self::assertFalse($config->has('app.name'));
    }

    public function testMissingDirectoryFails(): void
    {
        $this->expectException(RuntimeException::class);

        new ConfigurationLoader()->load(
            $this->directory . '/missing',
            new Environment()
        );
    }

    public function testNonArrayConfigurationFails(): void
    {
        $this->write('app.php', '<?php return "invalid";');

        $this->expectException(InvalidArgumentException::class);

        new ConfigurationLoader()->load(
            $this->directory,
            new Environment()
        );
    }

    public function testInvalidNamespaceFails(): void
    {
        $this->write('bad-name.php', '<?php return [];');

        $this->expectException(InvalidArgumentException::class);

        new ConfigurationLoader()->load(
            $this->directory,
            new Environment()
        );
    }

    public function testInvalidTopLevelKeyFails(): void
    {
        $this->write('app.php', '<?php return [0 => "invalid"];');

        $this->expectException(InvalidArgumentException::class);

        new ConfigurationLoader()->load(
            $this->directory,
            new Environment()
        );
    }

    private function write(string $filename, string $contents): void
    {
        $result = file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . $filename,
            $contents
        );

        if ($result === false) {
            self::fail('Unable to write temporary configuration fixture.');
        }
    }
}
