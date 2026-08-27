# Phase 1: Engineering Foundation

## Status

Complete after all acceptance commands pass.

## Purpose

Phase 1 creates the engineering controls and low-level conventions required
before runtime framework subsystems are implemented.

It deliberately avoids introducing application lifecycle, dependency
injection, configuration, HTTP, routing, middleware, persistence, logging, or
events.

## Runtime baseline

The minimum supported version is PHP 8.2.

Every framework PHP file must:

- declare strict types;
- use PSR-4 autoloading;
- follow PSR-12;
- use native parameter, property, and return types where possible;
- avoid dynamic properties;
- avoid hidden global state;
- remain testable without network services.

## Package boundary

The repository contains two Composer packages:

1. `caremillc/caremi` is the deployable application.
2. `caremillc/framework` is the reusable framework library.

The application consumes the framework through a Composer path repository.
This exercises the same package boundary expected when the framework is later
installed from a package registry.

## Namespace policy

- Framework production namespace: `Careminate\`
- Framework test namespace: `Careminate\Tests\`
- Application production namespace: `Caremi\`
- Application test namespace: `Caremi\Tests\`

A class must be stored at the PSR-4 path corresponding to its fully qualified
class name.

## Extension policy

Classes are final by default.

A class may remain extensible when inheritance is an intentional part of the
public design. Foundational exception classes are extensible because future
subsystems derive precise exceptions from them.

## Exception policy

Every exception owned by the framework implements:

`Careminate\Exception\ExceptionInterface`

Framework callers can catch either:

- the precise subsystem exception;
- an appropriate native exception category;
- the Careminate marker interface.

Future subsystems must prefer precise exception types over generic runtime
exceptions.

## Support utility policy

Support utilities are:

- stateless;
- final;
- non-instantiable;
- deterministic;
- free of service-container access;
- tested for Windows and POSIX behavior where applicable.

The lexical path-containment utility does not resolve symbolic links.
Security-sensitive filesystem operations must validate existing paths with
`realpath()` before accessing them.

## Quality gates

The framework has four mandatory automated gates:

1. PHPUnit tests.
2. PHPStan at level `max`.
3. PHP-CS-Fixer dry-run.
4. Rector dry-run.

CI also runs Composer package validation and dependency auditing.

No PHPStan baseline is permitted during the foundation phase. New code must
pass at the configured level without ignored errors.

## Lock-file policy

The root application must commit its generated `composer.lock` for
reproducible deployments.

The nested reusable framework package does not commit
`framework/composer.lock`. Its CI pipeline resolves dependencies within the
declared compatibility ranges.

## Phase boundary

The following features are intentionally deferred:

- PSR-11 container contracts and implementation;
- application bootstrap and lifecycle;
- service providers;
- module discovery and activation;
- configuration and environment loading;
- HTTP request and response abstractions;
- routing and middleware;
- controller dispatch;
- logging and events;
- database access.

## Acceptance criteria

Phase 1 is accepted when:

- both Composer packages validate;
- root integration tests pass;
- framework unit tests pass;
- PHPStan reports no errors;
- PHP-CS-Fixer reports no changes required;
- Rector dry-run reports no changes required;
- Composer audit reports no known vulnerable dependencies;
- `Careminate\Foundation\Version` loads through the root Composer package.

---
