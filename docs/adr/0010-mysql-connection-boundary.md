# ADR-0010 — MySQL Connection Boundary and Driver Extensibility

**Status:** Proposed  
**Framework:** Careminate 0.1.0-dev  
**Milestone:** Day 25

## Context

Careminate 0.1 requires MySQL persistence for its reference task-tracker application. The framework must provide safe configuration, deterministic connection selection and explicit lifecycle management without coupling application services to PDO MySQL types.

PostgreSQL and SQLite are not supported in this release.

## Decision

Introduce a driver-neutral `Connection` interface and a `ConnectionManager`.

The manager maintains named connection definitions, selects a configured default connection and accepts driver-specific factory closures.

Use PDO as the initial MySQL transport. MySQL-specific DSN construction, option validation and error handling belong to `MySqlConnection`.

Application configuration is loaded through the existing typed `Environment` and `ConfigurationLoader` components.

Connection objects are created lazily. Opening a network connection requires an explicit `connect()` call.

The application owns connection cleanup through its existing termination lifecycle.

## Connection guarantees

- No database network connection during normal application bootstrap.
- Default and named connection resolution is deterministic.
- Unknown names and unsupported drivers fail explicitly.
- Connections are reused within one manager instance.
- `disconnectAll()` releases cached adapter handles.
- MySQL uses PDO exceptions internally, with sanitized public connection failures.
- MySQL DSN fields are validated to prevent option injection.
- Persistent connections are disabled in the initial adapter.
- The default character set is `utf8mb4`.

`isConnected()` means that an adapter holds a connection handle. It does not prove that a remote MySQL server is currently reachable.

## Security

Database passwords are loaded from application environment configuration and must not be included in source control.

Public error messages do not contain driver exception messages, passwords, connection strings or server identifiers.

Remote MySQL TLS configuration is not part of the Day 25 adapter contract. The initial release topology assumes a local, trusted database connection. Remote deployments require separately reviewed TLS support.

Application health endpoints must not implicitly connect to the database. Dependency readiness is a separate capability.

## Alternatives considered

**Expose PDO directly to application services:** Rejected because repositories would depend on PDO implementation details.

**Implement several database adapters immediately:** Rejected because the 0.1 scope officially supports only MySQL.

**Connect during provider registration or application boot:** Rejected because unrelated HTTP and CLI functionality must remain available when MySQL is unavailable.

**Use a global persistent database singleton:** Rejected in favor of an application-owned manager with explicit cleanup.

**Introduce a general-purpose ORM immediately:** Deferred because the reference application first needs small, tested repository contracts.

## Deferred work

Day 26 introduces parameterized statement execution, result access, transactions and rollback behavior.

Day 27 introduces MySQL migrations and migration locking.

Days 28–30 implement reference schemas and task repositories.

PostgreSQL and SQLite adapters, cross-driver behavior and a general-purpose ORM remain outside Careminate 0.1.

## Verification requirements

Accept this decision when:

1. Connection-manager and configuration unit tests pass.
2. Application bootstrap succeeds without an active database.
3. A real MySQL connection succeeds and can be disconnected and reopened.
4. A real MySQL connection failure produces sanitized error output.
5. Existing framework and skeleton regression suites remain passing.
6. Public application persistence contracts do not depend on MySQL-specific classes.

**Status remains Proposed until these checks pass.**