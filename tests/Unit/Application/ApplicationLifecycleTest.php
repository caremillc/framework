<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\Exception\InvalidLifecycleTransitionException;
use Careminate\Application\Internal\ApplicationLifecycle;
use Careminate\Application\Internal\ApplicationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationLifecycleTest extends TestCase
{
    public function testNewLifecycleStartsCreated(): void
    {
        $lifecycle = new ApplicationLifecycle();

        self::assertSame(ApplicationState::Created, $lifecycle->state());
    }

    public function testSuccessfulLifecycleReachesTermination(): void
    {
        $lifecycle = new ApplicationLifecycle();

        $lifecycle->transitionTo(ApplicationState::Booting);

        self::assertSame(ApplicationState::Booting, $lifecycle->state());

        $lifecycle->transitionTo(ApplicationState::Booted);

        self::assertSame(ApplicationState::Booted, $lifecycle->state());

        $lifecycle->transitionTo(ApplicationState::Terminating);

        self::assertSame(ApplicationState::Terminating, $lifecycle->state());

        $lifecycle->transitionTo(ApplicationState::Terminated);

        self::assertSame(ApplicationState::Terminated, $lifecycle->state());
    }

    public function testRejectedTransitionPreservesStateAndAllowsValidProgress(): void
    {
        $lifecycle = new ApplicationLifecycle();

        self::assertRejected(
            $lifecycle,
            ApplicationState::Booted,
            'The application cannot transition from "created" to "booted".',
        );

        self::assertSame(ApplicationState::Created, $lifecycle->state());

        $lifecycle->transitionTo(ApplicationState::Booting);
        $lifecycle->transitionTo(ApplicationState::Booted);

        self::assertSame(ApplicationState::Booted, $lifecycle->state());
    }

    public function testBootCannotBeEnteredRecursively(): void
    {
        $lifecycle = new ApplicationLifecycle();
        $lifecycle->transitionTo(ApplicationState::Booting);

        self::assertRejected(
            $lifecycle,
            ApplicationState::Booting,
            'The application cannot transition from "booting" to "booting".',
        );

        self::assertSame(ApplicationState::Booting, $lifecycle->state());

        $lifecycle->transitionTo(ApplicationState::Booted);

        self::assertSame(ApplicationState::Booted, $lifecycle->state());
    }

    public function testTerminationCannotBeEnteredRecursively(): void
    {
        $lifecycle = new ApplicationLifecycle();

        $lifecycle->transitionTo(ApplicationState::Booting);
        $lifecycle->transitionTo(ApplicationState::Booted);
        $lifecycle->transitionTo(ApplicationState::Terminating);

        self::assertRejected(
            $lifecycle,
            ApplicationState::Terminating,
            'The application cannot transition from "terminating" to "terminating".',
        );

        self::assertSame(ApplicationState::Terminating, $lifecycle->state());

        $lifecycle->transitionTo(ApplicationState::Terminated);

        self::assertSame(ApplicationState::Terminated, $lifecycle->state());
    }

    /**
     * @param list<ApplicationState> $path
     */
    #[DataProvider('terminalPaths')]
    public function testTerminalStatesRejectEveryFurtherTransition(
        array $path,
        ApplicationState $terminal,
    ): void {
        $lifecycle = new ApplicationLifecycle();

        foreach ($path as $state) {
            $lifecycle->transitionTo($state);
        }

        self::assertSame($terminal, $lifecycle->state());

        foreach (ApplicationState::cases() as $next) {
            self::assertRejected(
                $lifecycle,
                $next,
                sprintf(
                    'The application cannot transition from "%s" to "%s".',
                    $terminal->value,
                    $next->value,
                ),
            );

            self::assertSame($terminal, $lifecycle->state());
        }
    }

    /**
     * @return iterable<string, array{
     *     list<ApplicationState>,
     *     ApplicationState
     * }>
     */
    public static function terminalPaths(): iterable
    {
        yield 'successful termination' => [
            [
                ApplicationState::Booting,
                ApplicationState::Booted,
                ApplicationState::Terminating,
                ApplicationState::Terminated,
            ],
            ApplicationState::Terminated,
        ];

        yield 'boot failure' => [
            [
                ApplicationState::Booting,
                ApplicationState::Failed,
            ],
            ApplicationState::Failed,
        ];

        yield 'termination failure' => [
            [
                ApplicationState::Booting,
                ApplicationState::Booted,
                ApplicationState::Terminating,
                ApplicationState::Failed,
            ],
            ApplicationState::Failed,
        ];
    }

    public function testLifecycleInstancesHaveIndependentState(): void
    {
        $first = new ApplicationLifecycle();
        $second = new ApplicationLifecycle();

        $first->transitionTo(ApplicationState::Booting);
        $first->transitionTo(ApplicationState::Failed);

        self::assertSame(ApplicationState::Failed, $first->state());
        self::assertSame(ApplicationState::Created, $second->state());

        $second->transitionTo(ApplicationState::Booting);
        $second->transitionTo(ApplicationState::Booted);

        self::assertSame(ApplicationState::Booted, $second->state());
        self::assertSame(ApplicationState::Failed, $first->state());
    }

    private static function assertRejected(
        ApplicationLifecycle $lifecycle,
        ApplicationState $next,
        string $expectedMessage,
    ): void {
        try {
            $lifecycle->transitionTo($next);
        } catch (InvalidLifecycleTransitionException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail('The lifecycle transition must be rejected.');
    }
}
