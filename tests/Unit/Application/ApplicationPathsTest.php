<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationPaths;
use Careminate\Application\Exception\InvalidApplicationInputException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationPathsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-paths-'
            . bin2hex(random_bytes(12));

        self::assertTrue(mkdir($directory, 0700));

        $resolved = realpath($directory);

        self::assertIsString($resolved);

        $this->directory = $resolved;
    }

    protected function tearDown(): void
    {
        self::assertTrue(rmdir($this->directory));
    }

    public function testRootIsCanonicalAndEmptySuffixReturnsIt(): void
    {
        $paths = new ApplicationPaths(
            $this->directory . DIRECTORY_SEPARATOR . '.',
        );

        self::assertSame($this->directory, $paths->base());
        self::assertSame($this->directory, $paths->path());
        self::assertSame($this->directory, $paths->path(''));
    }

    public function testBothSeparatorsProduceTheSameChildPath(): void
    {
        $paths = new ApplicationPaths($this->directory);

        $expected = $this->directory
            . DIRECTORY_SEPARATOR
            . 'storage'
            . DIRECTORY_SEPARATOR
            . 'cache';

        self::assertSame($expected, $paths->path('storage/cache'));
        self::assertSame($expected, $paths->path('storage\\cache'));
        self::assertDirectoryDoesNotExist($expected);
    }

    public function testRootRemainsStableAfterWorkingDirectoryChanges(): void
    {
        $paths = new ApplicationPaths($this->directory);
        $original = getcwd();

        self::assertIsString($original);

        try {
            self::assertTrue(chdir($this->directory));

            self::assertSame($this->directory, $paths->base());
            self::assertSame(
                $this->directory . DIRECTORY_SEPARATOR . 'app',
                $paths->path('app'),
            );
        } finally {
            self::assertTrue(chdir($original));
        }
    }

    public function testRelativeRootIsRejected(): void
    {
        $this->expectException(InvalidApplicationInputException::class);
        $this->expectExceptionMessage(
            'The application root must be an absolute directory path.',
        );

        new ApplicationPaths('.');
    }

    public function testMissingRootIsRejectedWithoutCreatingIt(): void
    {
        $missing = $this->directory . DIRECTORY_SEPARATOR . 'missing';

        try {
            new ApplicationPaths($missing);
        } catch (InvalidApplicationInputException $exception) {
            self::assertSame(
                'The application root must be an existing directory.',
                $exception->getMessage(),
            );
            self::assertDirectoryDoesNotExist($missing);

            return;
        }

        self::fail('A missing application root must be rejected.');
    }

    public function testRegularFileCannotBeTheRoot(): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'file';

        self::assertSame(0, file_put_contents($file, ''));

        try {
            $this->expectException(InvalidApplicationInputException::class);

            new ApplicationPaths($file);
        } finally {
            self::assertTrue(unlink($file));
        }
    }

    #[DataProvider('invalidRelativePaths')]
    public function testInvalidRelativePathsAreRejected(string $relative): void
    {
        $paths = new ApplicationPaths($this->directory);

        $this->expectException(InvalidApplicationInputException::class);

        $paths->path($relative);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRelativePaths(): iterable
    {
        yield 'parent' => ['..'];
        yield 'nested parent' => ['storage/../outside'];
        yield 'Windows parent' => ['storage\\..\\outside'];
        yield 'current directory' => ['.'];
        yield 'nested current directory' => ['storage/./cache'];
        yield 'absolute Unix path' => ['/outside'];
        yield 'absolute Windows path' => ['C:\\outside'];
        yield 'drive-relative path' => ['C:outside'];
        yield 'UNC path' => ['\\\\server\\share'];
        yield 'stream wrapper' => ['php://memory'];
        yield 'empty segment' => ['storage//cache'];
        yield 'trailing separator' => ['storage/'];
        yield 'null byte' => ["storage\0cache"];
        yield 'newline' => ["storage\ncache"];
        yield 'alternate data stream' => ['file:stream'];
    }

    public function testRejectedChildPathDoesNotAlterTheRoot(): void
    {
        $paths = new ApplicationPaths($this->directory);

        try {
            $paths->path('../outside');
        } catch (InvalidApplicationInputException) {
            self::assertSame($this->directory, $paths->base());
            self::assertSame(
                $this->directory . DIRECTORY_SEPARATOR . 'app',
                $paths->path('app'),
            );

            return;
        }

        self::fail('Parent traversal must be rejected.');
    }
}
