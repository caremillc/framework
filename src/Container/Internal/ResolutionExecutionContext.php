<?php

declare(strict_types=1);

namespace Careminate\Container\Internal;

use Careminate\Container\Exception\ResolutionException;
use Closure;
use Fiber;

/**
 * Tracks requester identity within one serialized container operation.
 *
 * @internal
 */
final class ResolutionExecutionContext
{
    private ?string $requester = null;

    /**
     * @var Fiber<mixed, mixed, mixed, mixed>|null
     */
    private ?Fiber $fiber = null;

    private int $depth = 0;

    public function requester(): ?string
    {
        $this->assertExecutionOwner();

        return $this->requester;
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function run(?string $requester, Closure $operation): mixed
    {
        $this->assertExecutionOwner();

        if ($this->depth === 0) {
            $this->fiber = Fiber::getCurrent();
        }

        $previous = $this->requester;
        $this->requester = $requester;
        ++$this->depth;

        try {
            return $operation();
        } finally {
            $this->requester = $previous;
            --$this->depth;

            if ($this->depth === 0) {
                $this->fiber = null;
            }
        }
    }

    private function assertExecutionOwner(): void
    {
        if (
            $this->depth > 0
            && $this->fiber !== Fiber::getCurrent()
        ) {
            throw new ResolutionException(
                'A container operation cannot overlap another Fiber execution.',
            );
        }
    }
}
