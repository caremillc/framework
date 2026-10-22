# ADR-0004: External Dependency Policy

Status: Proposed

## Context

A framework should not reimplement mature protocols,
cryptographic mechanisms, or infrastructure libraries
without a justified reason.

## Decision

Careminate uses Composer for dependency management.

The framework initially requires PHP 8.4 or newer.

Third-party libraries will be selected only when their
corresponding feature enters implementation scope.

Preferred integration categories include:

- PSR-compatible HTTP messages
- Console infrastructure
- Structured logging
- Escaped template rendering
- Database migration infrastructure
- Testing and static-analysis tools

Before selecting a dependency, verify:

1. Package identity and maintained release.
2. PHP 8.4 compatibility.
3. License compatibility.
4. Relevant public APIs.
5. Maintenance and security status.
6. Transitive dependency requirements.

Avoid dependencies on application-specific services.

## Versioning

The first release targets 0.1.0.

Pre-1.0 APIs may evolve, with documented compatibility
changes and migration instructions.

Application deployments use committed lockfiles.

Distributable framework libraries do not depend on
consumers using the framework's development lockfile.

## Alternatives

- Implement all infrastructure internally.
- Install every anticipated dependency immediately.

## Consequences

The core remains focused.

Dependency selection becomes part of each feature's
implementation and verification work.
