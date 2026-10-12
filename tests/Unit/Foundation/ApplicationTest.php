<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testItCanonicalizesTheBasePath(): void
    {
        $application = new Application(__DIR__ . '/..');

        self::assertSame(
            dirname(__DIR__),
            $application->basePath(),
        );
    }

    public function testItRejectsAnEmptyBasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Application('');
    }

    public function testItRejectsNullBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Application(__DIR__ . "\0");
    }

    public function testItRejectsAFileAsTheBasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Application(__FILE__);
    }

    public function testItRejectsAPathBeneathARegularFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Application(__FILE__ . '/missing-directory');
    }

    public function testApplicationsKeepIndependentBasePaths(): void
    {
        $first = new Application(__DIR__);
        $second = new Application(dirname(__DIR__));

        self::assertSame(__DIR__, $first->basePath());
        self::assertSame(dirname(__DIR__), $second->basePath());
        self::assertNotSame($first->basePath(), $second->basePath());
    }

    public function testConstructionDoesNotChangeTheWorkingDirectory(): void
    {
        $workingDirectory = getcwd();

        self::assertNotFalse($workingDirectory);

        new Application(__DIR__);

        self::assertSame($workingDirectory, getcwd());
    }
}
