<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Configuration;

use Flint\Configuration\Exception\ConfigurationException;
use Flint\Configuration\PhpConfigurationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpConfigurationLoaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'flint-config-');

        if ($path === false) {
            self::fail('Could not create the temporary configuration file.');
        }

        $this->path = $path;
    }

    protected function tearDown(): void
    {
        try {
            if (is_file($this->path)) {
                unlink($this->path);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testLoadsAndValidatesConfiguration(): void
    {
        $this->writeFile(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'app.name' => 'Flint',
                'app.debug' => false,
                'database.port' => 3306,
                'request.timeout' => 2.5,
                'optional' => null,
            ];
            PHP,
        );

        $configuration = new PhpConfigurationLoader()->load($this->path);

        self::assertSame('Flint', $configuration->string('app.name'));
        self::assertFalse($configuration->boolean('app.debug'));
        self::assertSame(3306, $configuration->integer('database.port'));
        self::assertSame(2.5, $configuration->float('request.timeout'));
        self::assertTrue($configuration->has('optional'));
        self::assertNull($configuration->get('optional'));
    }

    public function testRepeatedLoadingProducesIndependentConfigurations(): void
    {
        $this->writeFile(
            "<?php return ['app.name' => 'Flint'];",
        );

        $loader = new PhpConfigurationLoader();

        $first = $loader->load($this->path);
        $second = $loader->load($this->path);

        self::assertNotSame($first, $second);
        self::assertSame('Flint', $first->string('app.name'));
        self::assertSame('Flint', $second->string('app.name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'no return value' => ['<?php $value = 123;'];
        yield 'scalar result' => ["<?php return 'invalid';"];
        yield 'nested array' => [
            "<?php return ['database' => ['port' => 3306]];",
        ];
        yield 'integer key' => [
            "<?php return [123 => 'invalid'];",
        ];
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidConfigurationIsRejected(string $source): void
    {
        $this->writeFile($source);

        $this->expectException(ConfigurationException::class);

        new PhpConfigurationLoader()->load($this->path);
    }

    public function testMissingFileIsRejected(): void
    {
        self::assertTrue(unlink($this->path));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The configuration file must be an existing readable file.',
        );

        new PhpConfigurationLoader()->load($this->path);
    }

    public function testNullBytePathIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The configuration file must be an existing readable file.',
        );

        new PhpConfigurationLoader()->load("invalid\0path");
    }

    public function testExecutionFailureDoesNotExposeItsMessage(): void
    {
        $this->writeFile(
            <<<'PHP'
            <?php

            throw new RuntimeException('secret-value-for-this-test');
            PHP,
        );

        try {
            new PhpConfigurationLoader()->load($this->path);

            self::fail('Configuration evaluation must fail.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'The configuration file could not be evaluated.',
                $exception->getMessage(),
            );
            self::assertNull($exception->getPrevious());
        }
    }

    private function writeFile(string $source): void
    {
        $written = file_put_contents($this->path, $source);

        self::assertSame(
            strlen($source),
            $written,
            'The complete configuration fixture must be written.',
        );
    }
}
