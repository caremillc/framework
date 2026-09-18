<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Exception;

use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Exception\ExceptionInterface;
use Careminate\Exception\FrameworkException;
use Error;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;

final class ContainerExceptionTest extends TestCase
{
    /**
     * @param class-string<Exception> $exceptionClass
     */
    #[DataProvider('exceptionClasses')]
    public function testExceptionsPreserveBothFrameworkAndPsrContracts(
        string $exceptionClass,
    ): void {
        $previous = new Error('Underlying operation failed.');

        $exception = new $exceptionClass(
            message: 'Container operation failed.',
            code: 17,
            previous: $previous,
        );

        self::assertInstanceOf(
            ContainerExceptionInterface::class,
            $exception,
        );

        self::assertInstanceOf(ExceptionInterface::class, $exception);
        self::assertInstanceOf(FrameworkException::class, $exception);

        self::assertSame(
            'Container operation failed.',
            $exception->getMessage(),
        );

        self::assertSame(17, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * @return iterable<string, array{class-string<Exception>}>
     */
    public static function exceptionClasses(): iterable
    {
        yield 'missing entry' => [EntryNotFoundException::class];
        yield 'resolution failure' => [ResolutionException::class];
    }

    public function testMissingEntryMatchesThePsrNotFoundContract(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage(
            'No entry is registered for the requested identifier.',
        );

        throw new EntryNotFoundException(
            'No entry is registered for the requested identifier.',
        );
    }

    public function testResolutionFailureMatchesThePsrContainerContract(): void
    {
        $this->expectException(ContainerExceptionInterface::class);
        $this->expectExceptionMessage(
            'The requested entry could not be resolved.',
        );

        throw new ResolutionException(
            'The requested entry could not be resolved.',
        );
    }

    public function testMissingDependencyDoesNotClassifyItsOwnerAsMissing(): void
    {
        $missingDependency = new EntryNotFoundException(
            'A required dependency is not registered.',
        );

        $failure = new ResolutionException(
            message: 'The registered entry could not be resolved.',
            previous: $missingDependency,
        );

        $reflection = new ReflectionClass($failure);

        self::assertNotContains(
            NotFoundExceptionInterface::class,
            $reflection->getInterfaceNames(),
        );

        self::assertSame(
            $missingDependency,
            $failure->getPrevious(),
        );
    }

    public function testResolutionWrappingPreservesTheEntireCauseChain(): void
    {
        $original = new Error('Underlying operation failed.');

        $dependencyFailure = new ResolutionException(
            message: 'A dependency could not be resolved.',
            previous: $original,
        );

        $entryFailure = new ResolutionException(
            message: 'The requested entry could not be resolved.',
            previous: $dependencyFailure,
        );

        self::assertSame(
            $dependencyFailure,
            $entryFailure->getPrevious(),
        );

        self::assertSame(
            $original,
            $dependencyFailure->getPrevious(),
        );
    }
}
