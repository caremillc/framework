<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Exception;

use Careminate\Exception\ExceptionInterface;
use Careminate\Exception\FrameworkException;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FrameworkExceptionTest extends TestCase
{
    public function testNativeConstructorDefaultsArePreserved(): void
    {
        $exception = new class () extends FrameworkException {
        };

        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    public function testMessageCodeAndCompleteCauseChainArePreserved(): void
    {
        $original = new Error('Underlying operation failed.');

        $previous = new RuntimeException(
            message: 'Adapter operation failed.',
            code: 12,
            previous: $original,
        );

        $exception = new class (
            message: 'Framework operation failed.',
            code: 27,
            previous: $previous,
        ) extends FrameworkException {
        };

        self::assertSame(
            'Framework operation failed.',
            $exception->getMessage(),
        );

        self::assertSame(27, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame($original, $previous->getPrevious());
    }

    public function testAnEngineErrorCanBeTheImmediateCause(): void
    {
        $previous = new Error('Underlying operation failed.');

        $exception = new class (
            message: 'Framework operation failed.',
            previous: $previous,
        ) extends FrameworkException {
        };

        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
    }

    public function testRuntimeFailuresMatchTheCommonCatchContract(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('Framework operation failed.');

        throw new class ('Framework operation failed.') extends FrameworkException {
        };
    }

    public function testTheCatchContractSupportsOtherNativeExceptionFamilies(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('An argument is invalid.');

        throw new class ('An argument is invalid.') extends InvalidArgumentException implements ExceptionInterface {
        };
    }
}
