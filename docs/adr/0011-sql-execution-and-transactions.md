# ADR-0011 — SQL Execution and Transaction Ownership

**Status:** Proposed  
**Milestone:** Day 26  
**Framework:** Careminate 0.1.0-dev

## Context

The Day 25 connection layer provides driver-neutral connection lifecycle management and an initial PDO MySQL adapter.

Application repositories require bound SQL values, inspectable results, predictable transaction ownership and safe failure handling before persistence features can be built.

## Decision

Introduce `SqlConnection` as an extension of the existing `Connection` interface. Keep existing connection factories and manager APIs unchanged.

Introduce `SqlBindings` for driver-independent parameter validation and `SqlResult` for eager result snapshots.

The MySQL adapter uses native PDO prepared statements with emulation disabled.

Only positional lists and named parameter maps are supported. Parameter values are integers, strings, booleans or null. Floats are rejected; exact decimals must be represented as validated decimal strings.

## Transaction ownership

One `SqlConnection` owns one active transaction.

- `beginTransaction()` starts a transaction.
- `commit()` completes it.
- `rollBack()` cancels it.
- `transactional()` commits when its callback succeeds.
- `transactional()` rolls back when its callback throws.
- Nested transactions are rejected; no savepoints are created.
- An unexpected commit or rollback failure discards the connection.
- The adapter never automatically retries a failed commit.

After successful rollback, the original callback exception is propagated. If rollback itself fails, a sanitized transaction exception is raised.

Connections are not shared as distributed transaction participants.

## Security and failure behavior

SQL fragments and identifiers are trusted application code. Untrusted scalar values are supplied only as bound parameters.

Invalid parameter names, mixed parameter styles, unsupported parameter types and empty SQL are rejected.

Driver query exceptions are converted to sanitized `QueryException` messages without exposing the original PDO exception chain.

Connection construction does not contact MySQL. Network connection occurs when explicitly requested or when execution or transaction start requires it.

Disconnecting an active connection attempts rollback before releasing the handle.

## Limitations

Returned result rows are buffered in memory.

Affected-row counts are intended for write statements, not reliable SELECT cardinality.

MySQL DDL may implicitly commit and cannot be assumed to roll back.

Deadlock retries, savepoints, nested transaction emulation, cursor streaming, a query builder and PostgreSQL/SQLite implementations are not part of this increment.

Generated IDs are returned as strings to avoid integer-width assumptions.

## Acceptance

Acceptance requires passing framework unit/static-analysis tests and real MySQL integration tests for bound values, commit, rollback, nested-transaction rejection, error sanitization and connection cleanup.

The ADR becomes Accepted only after real MySQL evidence is recorded.