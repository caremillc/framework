<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Exception;

use Careminate\Http\Exception\HttpException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpExceptionTest extends TestCase
{
    public function testStoresHttpStatus(): void
    {
        $exception = new HttpException(
            404,
            'Internal diagnostic',
        );

        self::assertSame(404, $exception->status);
        self::assertSame(404, $exception->getCode());
        self::assertSame(
            'Internal diagnostic',
            $exception->getMessage(),
        );
    }

    public function testPreservesPreviousException(): void
    {
        $previous = new RuntimeException('Original');

        $exception = new HttpException(
            503,
            'Unavailable',
            $previous,
        );

        self::assertSame(
            $previous,
            $exception->getPrevious(),
        );
    }

    public function testRejectsNonErrorStatus(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new HttpException(200);
    }

    public function testRejectsStatusAbove599(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new HttpException(600);
    }
}
