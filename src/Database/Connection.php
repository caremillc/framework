<?php

declare(strict_types=1);

namespace Careminate\Database;

/**
 * Driver-neutral database connection lifecycle.
 *
 * Query execution and transaction boundaries are introduced
 * separately in Day 26.
 */
interface Connection
{
    public function driver(): string;

    public function connect(): void;

    /**
     * Whether this object holds a connection handle.
     *
     * This is not a remote database health probe.
     */
    public function isConnected(): bool;

    public function disconnect(): void;
}
