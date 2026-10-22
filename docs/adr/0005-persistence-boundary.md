# ADR-0005: Persistence Boundary

Status: Proposed

## Context

Careminate 0.1 supports MySQL.

PostgreSQL and SQLite are planned for a later persistence
expansion, without requiring a rewrite of application
repositories.

## Decision

Use PDO as the initial relational transport.

Separate responsibilities for:

- Connection configuration and creation
- Connection ownership and cleanup
- Parameterized statement execution
- Transaction management
- Driver identification
- SQL dialect-specific operations
- Migration behavior

Application repositories must not require MySQL-specific
connection classes.

Connection creation is lazy.

External values are bound as query parameters.

SQL identifiers are validated and handled through
driver-aware SQL generation.

Transactions belong to individual connections.

Nested transaction behavior must be explicitly defined
and tested before becoming a supported API.

MySQL migrations must define version tracking, locking,
and failure behavior.

## Supported drivers

0.1: MySQL only.

0.4 target: PostgreSQL and SQLite after adapter,
integration, migration, and documentation verification.

## Alternatives

- MySQL-specific persistence APIs.
- Complete ORM implementation in 0.1.
- Immediate support for several database engines.

## Consequences

Driver boundaries introduce limited additional structure.

The framework does not claim multi-database support
until additional adapters pass their acceptance tests.

## Verification

Use a real MySQL server to test connections, binding,
transactions, cleanup, migrations, and failure cases.



4. Important PHP security update
There is one newly verified toolchain issue worth addressing before further development.

The PHP project published PHP 8.5.12 and 8.4.27 on October 21, 2026, fixing critical remote-code-execution vulnerabilities. Your reported PHP 8.5.11 predates this update. 

php.net
+1
