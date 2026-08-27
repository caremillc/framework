<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

use Throwable;

final class TerminationException extends ApplicationException
{
    /**
     * @param non-empty-list<Throwable> $failures
     */
    public function __construct(
        private readonly array $failures,
    ) {
        parent::__construct(
            sprintf(
                '%d application termination operation(s) failed. First failure: %s',
                count($failures),
                $failures[0]->getMessage(),
            ),
            0,
            $failures[0],
        );
    }

    /**
     * @param non-empty-list<Throwable> $failures
     */
    public static function fromFailures(array $failures): self
    {
        return new self($failures);
    }

    /**
     * @return non-empty-list<Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
