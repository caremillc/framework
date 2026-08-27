<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Exception;

use Careminate\Exception\ExceptionInterface;
use Careminate\Exception\InvalidArgumentException;
use Careminate\Exception\LogicException;
use Careminate\Exception\RuntimeException;
use Careminate\Exception\UnexpectedValueException;
use InvalidArgumentException as NativeInvalidArgumentException;
use LogicException as NativeLogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException as NativeRuntimeException;
use Throwable;
use UnexpectedValueException as NativeUnexpectedValueException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testRuntimeExceptionBelongsToFrameworkHierarchy(): void
    {
        $exception = new RuntimeException('Runtime failure.');

        self::assertInstanceOf(NativeRuntimeException::class, $exception);
        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf(Throwable::class, $exception);
    }

    public function testLogicExceptionBelongsToFrameworkHierarchy(): void
    {
        $exception = new LogicException('Logic failure.');

        self::assertInstanceOf(NativeLogicException::class, $exception);
        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    public function testInvalidArgumentExceptionBelongsToFrameworkHierarchy(): void
    {
        $exception = new InvalidArgumentException('Invalid argument.');

        self::assertInstanceOf(NativeInvalidArgumentException::class, $exception);
        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    public function testUnexpectedValueExceptionBelongsToFrameworkHierarchy(): void
    {
        $exception = new UnexpectedValueException('Unexpected value.');

        self::assertInstanceOf(
            NativeUnexpectedValueException::class,
            $exception,
        );
        self::assertInstanceOf(ExceptionInterface::class, $exception);
    }
}
