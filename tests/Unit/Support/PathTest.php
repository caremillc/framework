<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Support;

use Careminate\Exception\InvalidArgumentException;
use Careminate\Support\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    #[DataProvider('normalizationProvider')]
    public function testPathNormalization(
        string $path,
        string $expected,
    ): void {
        self::assertSame($expected, Path::normalize($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationProvider(): iterable
    {
        yield 'Windows path' => [
            'C:\\xampp\\htdocs\\caremi\\\\framework\\src',
            'C:/xampp/htdocs/caremi/framework/src',
        ];

        yield 'POSIX path' => [
            '/var/www/./caremi/../application',
            '/var/www/application',
        ];

        yield 'relative path' => [
            'framework/src/../tests',
            'framework/tests',
        ];

        yield 'current directory' => [
            '.',
            '.',
        ];

        yield 'UNC path' => [
            '\\\\server\\share\\framework\\src',
            '//server/share/framework/src',
        ];
    }

    public function testJoinBuildsNormalizedPath(): void
    {
        self::assertSame(
            'C:/xampp/htdocs/caremi/framework/src',
            Path::join(
                'C:\\xampp\\htdocs\\caremi\\',
                'framework',
                'src',
            ),
        );

        self::assertSame(
            '/var/www/caremi',
            Path::join('/', 'var', 'www', 'caremi'),
        );
    }

    public function testAbsolutePathDetection(): void
    {
        self::assertTrue(Path::isAbsolute('C:\\xampp\\htdocs'));
        self::assertTrue(Path::isAbsolute('/var/www'));
        self::assertTrue(Path::isAbsolute('\\\\server\\share'));
        self::assertFalse(Path::isAbsolute('framework/src'));
    }

    public function testWindowsContainmentIsCaseInsensitive(): void
    {
        self::assertTrue(
            Path::isWithin(
                'C:\\xampp\\htdocs\\caremi',
                'C:\\XAMPP\\HTDOCS\\CAREMI\\framework\\src',
            ),
        );
    }

    public function testRelativeCandidateCanBeInsideBase(): void
    {
        self::assertTrue(
            Path::isWithin(
                'C:\\xampp\\htdocs\\caremi',
                'framework\\src',
            ),
        );
    }

    public function testTraversalOutsideBaseIsRejected(): void
    {
        self::assertFalse(
            Path::isWithin(
                'C:\\xampp\\htdocs\\caremi',
                '..\\private\\secrets',
            ),
        );
    }

    public function testSimilarPrefixIsNotInsideBase(): void
    {
        self::assertFalse(
            Path::isWithin(
                '/var/www/caremi',
                '/var/www/caremi-backup',
            ),
        );
    }

    public function testRelativeBaseIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        Path::isWithin('caremi', 'framework/src');
    }

    public function testEmptyPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        Path::normalize('');
    }

    public function testIncompleteUncPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('server and a share name');

        Path::normalize('\\\\server');
    }
}
