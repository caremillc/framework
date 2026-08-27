# ADR 0003: Explicit container registration and compilation

- Status: Accepted
- Date: 2026-08-26

## Context

Careminate requires a PSR-11 container that supports simple applications,
modular enterprise applications, long-running workers, and production
optimization.

Uncontrolled registration overrides create order-dependent behavior.
A global active scope is unsafe for Fibers and concurrent runtimes.
Serialized object caches create security and compatibility risks.

## Decision

The container uses:

- PSR-11 for lookup interoperability;
- separate Careminate contracts for registration;
- explicit transient, singleton, and scoped lifetimes;
- independent scoped-container objects;
- constructor autowiring;
- exact consumer-based contextual bindings;
- explicit `replace()` operations;
- native PHP 8.4 lazy proxies;
- executable PHP arrays for compiled definition caches;
- frozen containers after compiled-cache loading.

Duplicate registrations are rejected unless `replace()` is used.

Scopes own their scoped instances and must be closed after use.

Compilation supports:

- class definitions;
- factory-service identifiers;
- scalar values;
- arrays containing exportable values;
- aliases;
- tags;
- contextual scalar and class-name bindings.

Runtime closures, object factories, resources, and object instances are not
compiled.

## Consequences

### Positive

- PSR interoperability is preserved.
- Accidental registration collisions fail immediately.
- Request and worker scopes do not rely on mutable global state.
- Compiled cache files do not use PHP deserialization.
- Lazy services require no generated proxy classes.
- Cache loading reconstructs a validated, immutable container.

### Negative

- Runtime closures cannot be placed in the compiled container.
- Native lazy proxies require PHP 8.4.
- Scoped services require explicit scope management.
- Reflection is still used for constructor autowiring when a class is first
  resolved.

## Future work

Phase 38 may add ahead-of-time constructor invocation plans and benchmark-based
reflection-elimination where measurements justify the extra generated code.

---
