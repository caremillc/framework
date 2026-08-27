<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationBuilder;
use Careminate\Application\ApplicationState;
use Careminate\Application\Runtime\ConsoleRuntime;
use Careminate\Application\Runtime\HttpRuntime;
use Careminate\Application\Runtime\RuntimeContext;
use Careminate\Application\Runtime\RuntimeResult;
use Careminate\Application\Runtime\RuntimeType;
use Careminate\Contracts\Application\KernelInterface;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ConsoleLifecycleKernel implements KernelInterface
{
    public bool $terminated = false;

    public function handle(RuntimeContext $context): RuntimeResult
    {
        self::assertSame(RuntimeType::Console, $context->type);

        return new RuntimeResult(0, 'console-output');
    }

    public function terminate(
        RuntimeContext $context,
        ?RuntimeResult $result,
        ?Throwable $failure,
    ): void {
        unset($context, $result, $failure);

        $this->terminated = true;
    }
}

final class HttpLifecycleKernel implements KernelInterface
{
    public function handle(RuntimeContext $context): RuntimeResult
    {
        self::assertSame(RuntimeType::Http, $context->type);

        return new RuntimeResult(
            200,
            ['status' => 'ok'],
        );
    }

    public function terminate(
        RuntimeContext $context,
        ?RuntimeResult $result,
        ?Throwable $failure,
    ): void {
        unset($context, $result, $failure);
    }
}

final class RuntimeLifecycleTest extends TestCase
{
    public function testConsoleRuntimeLifecycle(): void
    {
        $output = '';
        $kernel = new ConsoleLifecycleKernel();

        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->kernel(RuntimeType::Console, $kernel)
            ->build();

        $runtime = new ConsoleRuntime(
            ['caremi', 'about'],
            static function (string $message) use (&$output): void {
                $output .= $message;
            },
        );

        $exitCode = $application->run($runtime);

        self::assertSame(0, $exitCode);
        self::assertSame('console-output', $output);
        self::assertTrue($kernel->terminated);
        self::assertTrue($runtime->isTerminated());
        self::assertSame(
            ApplicationState::Bootstrapped,
            $application->state(),
        );
    }

    public function testHttpRuntimeLifecycle(): void
    {
        $emittedResult = null;

        $application = ApplicationBuilder::fromBasePath('/var/www/caremi')
            ->kernel(RuntimeType::Http, new HttpLifecycleKernel())
            ->build();

        $runtime = new HttpRuntime(
            ['REQUEST_METHOD' => 'GET'],
            ['page' => '1'],
            [],
            [],
            [],
            static function (RuntimeResult $result) use (&$emittedResult): int {
                $emittedResult = $result;

                return 0;
            },
        );

        self::assertSame(0, $application->run($runtime));
        self::assertInstanceOf(RuntimeResult::class, $emittedResult);
        self::assertSame(200, $emittedResult->code);
        self::assertTrue($runtime->isTerminated());
    }
}
