<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Application\BootstrapperInterface;
use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Module\Internal\ModuleScopedBootstrapperFactory;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final class ModuleScopedBootstrapperFactoryTest extends TestCase
{
    public function testBootstrapReceivesTheBoundContainer(): void
    {
        $applicationContainer = $this->createStub(ContainerInterface::class);
        $moduleContainer = $this->createStub(ContainerInterface::class);

        $delegate = $this->createMock(BootstrapperInterface::class);

        $delegate
            ->expects(self::once())
            ->method('bootstrap')
            ->with(self::identicalTo($moduleContainer));

        $wrapped = new ModuleScopedBootstrapperFactory()->wrap(
            $delegate,
            $moduleContainer,
        );

        $wrapped->bootstrap($applicationContainer);
    }

    public function testNonterminableHandlerRemainsNonterminable(): void
    {
        $delegate = $this->createStub(BootstrapperInterface::class);
        $moduleContainer = $this->createStub(ContainerInterface::class);

        $wrapped = new ModuleScopedBootstrapperFactory()->wrap(
            $delegate,
            $moduleContainer,
        );

        self::assertNotContains(
            TerminableBootstrapperInterface::class,
            new ReflectionClass($wrapped)->getInterfaceNames(),
        );
    }

    public function testBootAndTerminationUseTheSameDelegateAndContainer(): void
    {
        $applicationContainer = $this->createStub(ContainerInterface::class);
        $moduleContainer = $this->createStub(ContainerInterface::class);

        $delegate = $this->createMock(TerminableBootstrapperInterface::class);

        $delegate
            ->expects(self::once())
            ->method('bootstrap')
            ->with(self::identicalTo($moduleContainer));

        $delegate
            ->expects(self::once())
            ->method('terminate')
            ->with(self::identicalTo($moduleContainer));

        $wrapped = new ModuleScopedBootstrapperFactory()->wrap(
            $delegate,
            $moduleContainer,
        );

        self::assertInstanceOf(TerminableBootstrapperInterface::class, $wrapped);

        $wrapped->bootstrap($applicationContainer);
        $wrapped->terminate($applicationContainer);
    }

    public function testBootstrapFailureIsPropagatedWithoutImplicitCleanup(): void
    {
        $original = new Error('Module bootstrap failed.');
        $applicationContainer = $this->createStub(ContainerInterface::class);
        $moduleContainer = $this->createStub(ContainerInterface::class);

        $delegate = $this->createMock(TerminableBootstrapperInterface::class);

        $delegate
            ->expects(self::once())
            ->method('bootstrap')
            ->with(self::identicalTo($moduleContainer))
            ->willThrowException($original);

        $delegate
            ->expects(self::never())
            ->method('terminate');

        $wrapped = new ModuleScopedBootstrapperFactory()->wrap(
            $delegate,
            $moduleContainer,
        );

        try {
            $wrapped->bootstrap($applicationContainer);
        } catch (Error $exception) {
            self::assertSame($original, $exception);

            return;
        }

        self::fail('The original bootstrap failure must propagate.');
    }

    public function testTerminationFailureIsPropagatedUnchanged(): void
    {
        $original = new Error('Module termination failed.');
        $applicationContainer = $this->createStub(ContainerInterface::class);
        $moduleContainer = $this->createStub(ContainerInterface::class);

        $delegate = $this->createMock(TerminableBootstrapperInterface::class);

        $delegate
            ->expects(self::once())
            ->method('bootstrap')
            ->with(self::identicalTo($moduleContainer));

        $delegate
            ->expects(self::once())
            ->method('terminate')
            ->with(self::identicalTo($moduleContainer))
            ->willThrowException($original);

        $wrapped = new ModuleScopedBootstrapperFactory()->wrap(
            $delegate,
            $moduleContainer,
        );

        self::assertInstanceOf(TerminableBootstrapperInterface::class, $wrapped);

        $wrapped->bootstrap($applicationContainer);

        try {
            $wrapped->terminate($applicationContainer);
        } catch (Error $exception) {
            self::assertSame($original, $exception);

            return;
        }

        self::fail('The original termination failure must propagate.');
    }

    public function testSeparateAdaptersRetainTheirOwnContainers(): void
    {
        $applicationContainer = $this->createStub(ContainerInterface::class);
        $firstContainer = $this->createStub(ContainerInterface::class);
        $secondContainer = $this->createStub(ContainerInterface::class);

        $firstDelegate = $this->createMock(BootstrapperInterface::class);
        $secondDelegate = $this->createMock(BootstrapperInterface::class);

        $firstDelegate
            ->expects(self::once())
            ->method('bootstrap')
            ->with(self::identicalTo($firstContainer));

        $secondDelegate
            ->expects(self::once())
            ->method('bootstrap')
            ->with(self::identicalTo($secondContainer));

        $factory = new ModuleScopedBootstrapperFactory();

        $first = $factory->wrap($firstDelegate, $firstContainer);
        $second = $factory->wrap($secondDelegate, $secondContainer);

        $second->bootstrap($applicationContainer);
        $first->bootstrap($applicationContainer);
    }
}
