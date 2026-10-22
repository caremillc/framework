# ADR-0001: Package Boundaries

Status: Proposed

## Context

Careminate requires independently reusable framework
components and a separate installable application skeleton.

Premature package splitting increases maintenance and
dependency-management complexity.

## Decision

The first release uses one framework Composer package:

- Package: careminate/framework
- Namespace: Careminate\
- Minimum PHP: 8.4
- License: MIT

The application skeleton is a separate repository.

Framework component namespaces are organized by
responsibility, including Container, Configuration,
Application, Http, Routing, Database, and Security.

Internal implementation classes may use Internal
subnamespaces.

Only explicitly documented contracts are public APIs.

## Dependency rules

1. Framework code must not depend on the skeleton.
2. The skeleton depends on the framework.
3. Components must not form circular dependencies.
4. Infrastructure integrations should remain replaceable
   where real application requirements justify it.
5. External packages must have verified compatibility.
6. Advanced components remain outside release 0.1.

## Alternatives

- Multiple Composer component packages immediately.
- One repository containing both framework and application.

## Consequences

A single framework package simplifies initial development
and release management.

Component boundaries must still be enforced through
architecture reviews and tests.

Package extraction may be considered after 0.1.