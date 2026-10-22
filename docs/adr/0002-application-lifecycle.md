# ADR-0002: Application Lifecycle

Status: Proposed

## Context

Application construction, boot, execution, and termination
must be predictable and independently testable.

The initial deployment uses PHP-FPM request-per-process.

## Decision

The application lifecycle has these stages:

1. Construct application.
2. Load and validate configuration.
3. Register service providers.
4. Boot registered providers.
5. Handle an HTTP request or CLI command.
6. Terminate execution.
7. Release owned resources.

Provider registration order is explicit and deterministic.

Configuration is resolved once during bootstrap.

Services must not depend on arbitrary environment reads
during request execution.

## Failure behavior

Construction and configuration failures stop bootstrap.

Provider failures stop boot and report safe diagnostics.

Execution failures enter the exception-handling boundary.

Cleanup runs for resources acquired before a failure,
including partially completed bootstrap operations.

Cleanup failures are reported without hiding the original
failure.

## Alternatives

- Global application state.
- Implicit provider discovery.
- Long-running workers from the first release.

## Consequences

Lifecycle ownership remains explicit.

Future worker support requires separate scope and
resource-reset guarantees.

## Verification

Tests must cover boot order, repeated boot attempts,
failure handling, and cleanup.

