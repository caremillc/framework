# ADR 0004: Explicit application and runtime lifecycle

- Status: Accepted
- Date: 2026-08-26

## Context

Careminate must support short-lived HTTP and console execution as well as
long-running workers, real-time servers, desktop runtimes, and serverless
adapters.

A single global request object or mutable current-scope variable would create
cross-request leakage and Fiber-safety risks.

HTTP-specific request objects cannot be introduced before the HTTP foundation,
and console-command objects cannot be introduced before the console phase.

## Decision

The application kernel uses:

- an explicit application state machine;
- ordered bootstrappers;
- a runtime-neutral `RuntimeContext`;
- runtime-specific adapters;
- runtime-specific kernels;
- an independent container scope per execution;
- explicit kernel and runtime termination;
- deferred application termination requests;
- reverse-priority application termination hooks.

The initial HTTP runtime captures raw input but does not claim PSR-7
compatibility. Phase 7 replaces raw HTTP input with PSR-7 request and response
objects.

The initial console runtime exposes argument data but does not implement command
routing. Phase 9 provides commands, input parsing, and interactive output.

## Consequences

### Positive

- Runtime scopes do not rely on global mutable state.
- HTTP, console, worker, desktop, and test execution share one lifecycle.
- Cleanup runs after successful and failed kernel execution.
- Long-running processes can request graceful termination.
- Runtime-specific features can evolve behind stable contracts.

### Negative

- Initial HTTP and console adapters are deliberately minimal.
- Application kernels must return a generic runtime result until specialized
  result contracts are introduced.
- Kernel registration is separate from service registration.

## Production optimization

The application optimizer delegates container compilation to Phase 2 and
records:

- compilation duration;
- memory change;
- cache-file size.

The cache directory must exist before optimization. Deployment tooling will
create and permission runtime directories in Phase 40.
---

