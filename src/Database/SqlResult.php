<?php

declare(strict_types=1);

namespace Careminate\Database;

/** Eager, disconnected snapshot of a database statement result. */
final readonly class SqlResult
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private array $rows,
        public int $affectedRows,
    ) {
        if ($affectedRows < 0) {
            throw new \InvalidArgumentException(
                'Invalid affected-row count.',
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    public function returnedRowCount(): int
    {
        return count($this->rows);
    }
}
