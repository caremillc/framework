<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationBuilder;
use Careminate\Application\ApplicationState;
use Careminate\Application\Runtime\ConsoleRuntime;
use Careminate\Application\Runtime\RuntimeContext;
use Careminate\Application\Runtime\RuntimeResult;
use Careminate\Application\Runtime\RuntimeType;
use Careminate\Application\Termination\TerminationContext;
use Careminate\Contracts\Application\KernelInterface;
use Careminate\Contracts\Application\TerminationHookInterface;
use Careminate\Exception\Application\RuntimeExecutionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class FailingKernel implements KernelInterface
{
    public bool $terminated = false;

    public function handle(RuntimeContext $context): RuntimeResult
    {
        unset($context);

        throw new RuntimeException('Kernel failed.');
    }

    public function terminate(
        RuntimeContext $context,
        ?RuntimeResult $result,
        ?Throwable $failure,
    ): void {
        unset($context, $result);

        self::assertInstanceOf(RuntimeException::class, $failure);

        $this->terminated = true;
    }
}

final class FirstTerminationHook implements TerminationHookInterface
{
    /**
     * @param list<string> $calls
     */
    public function __construct(private array &$calls)
    {
    }

    public function terminate(TerminationContext $context): void
    {
        unset($context);

        $this->calls[] = 'first';
    }
}

final class SecondTerminationHook implements TerminationHookInterface
{
    /**
     * @param list<string> $calls
     */
    public function __construct(private array &$calls)
    {
    }

    public function terminate(TerminationContext $context): void
    {
        unset($context);

        $this->calls[] = 'second';
    }
}

final class FailureAndTerminationTest extends TestCase
{
    public function testRuntimeFailureStillTerminatesKernelAndRuntime(): void
    {
        $kernel = new FailingKernel();

        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->kernel(RuntimeType::Console, $kernel)
            ->build();

        $runtime = new ConsoleRuntime(['caremi']);

        try {
            $application->run($runtime);
            self::fail('Expected runtime execution failure.');
        } catch (RuntimeExecutionException $exception) {
            self::assertSame(
                'Kernel failed.',
                $exception->getPrevious()?->getMessage(),
            );
        }

        self::assertTrue($kernel->terminated);
        self::assertTrue($runtime->isTerminated());
        self::assertSame(ApplicationState::Failed, $application->state());

        $application->terminate();

        self::assertSame(
            ApplicationState::Terminated,
            $application->state(),
        );
    }

    public function testTerminationHooksUsePriorityOrder(): void
    {
        $calls = [];

        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->terminationHook(new FirstTerminationHook($calls), 10)
            ->terminationHook(new SecondTerminationHook($calls), 20)
            ->build();

        $application->terminate();

        self::assertSame(['second', 'first'], $calls);
    }

    public function testRequestedTerminationOccursAfterRuntimeCleanup(): void
    {
        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->kernel(
                RuntimeType::Console,
                new class implements KernelInterface {
                    public function handle(
                        RuntimeContext $context,
                    ): RuntimeResult {
                        unset($context);

                        return new RuntimeResult(0);
                    }

                    public function terminate(
                        RuntimeContext $context,
                        ?RuntimeResult $result,
                        ?Throwable $failure,
                    ): void {
                        unset($context, $result, $failure);
                    }
                },
            )
            ->build();

        $application->requestTermination();
        $application->run(new ConsoleRuntime(['caremi']));

        self::assertSame(
            ApplicationState::Terminated,
            $application->state(),
        );
    }
}
