# ADR-0003: Dependency Injection Container

Status: Proposed

## Context

Careminate needs predictable service resolution without
introducing advanced container features prematurely.

## Decision

The 0.1 container supports:

- Explicit bindings
- Factory bindings
- Existing instances
- Transient services
- Singleton services
- Aliases
- Constructor autowiring
- Circular dependency detection
- Actionable resolution errors

Transient bindings produce a new instance per resolution.

Singleton bindings retain one instance per container.

Existing instance bindings return the registered instance.

Aliases resolve to their canonical service and preserve
the canonical service's lifetime.

Autowiring supports concrete classes with resolvable
constructor dependencies.

Unresolved interfaces, abstract classes, ambiguous types,
and required scalar parameters fail with diagnostics.

Exact constructor parameter rules are established and
tested during Day 7.

## Deferred

- Scoped services
- Contextual bindings
- Tagged services
- Deferred services
- Lazy proxies
- Compiled definitions

## Alternatives

- Full-featured container immediately.
- Global service locator.
- Autowiring without explicit binding support.

## Consequences

The initial implementation remains small and testable.

Advanced capabilities require later ADRs and regression
tests before entering the public API.

## Verification

Test identity, factory behavior, aliases, autowiring,
missing dependencies, and dependency cycles.
