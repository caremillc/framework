# Phase 1: Engineering Foundation

## Status

Implemented. Runtime compatibility corrected during Phase 2.

## Purpose

Phase 1 creates the engineering controls and low-level conventions required
before runtime framework subsystems are implemented.

## Runtime baseline

The minimum supported version is PHP 8.4.

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

## Namespace policy

- Framework production namespace: `Careminate\`
- Framework test namespace: `Careminate\Tests\`
- Application production namespace: `Caremi\`
- Application test namespace: `Caremi\Tests\`

## Extension policy

Classes are final by default.

A class may remain extensible when inheritance is an intentional part of the
public design. Foundational exception classes are extensible because framework
subsystems derive precise exceptions from them.

## Exception policy

Every exception owned by the framework implements:

`Careminate\Exception\ExceptionInterface`

Subsystem exceptions should preserve an appropriate native or PSR exception
contract where one exists.

## Support utility policy

Support utilities are stateless, final, non-instantiable, deterministic,
free of container access, and tested for Windows and POSIX behavior.

## Quality gates

The framework has four mandatory automated gates:

1. PHPUnit 12.
2. PHPStan at level `max`.
3. PHP-CS-Fixer dry-run.
4. Rector dry-run.

CI also runs Composer validation and dependency auditing.

## Lock-file policy

The root application commits `composer.lock` for reproducible deployments.

The nested framework package does not commit `framework/composer.lock`.

## Compatibility correction

The initial Phase 1 response specified PHP 8.2. The project master
specification requires PHP 8.4 and newer stable versions.

Phase 2 corrects:

- both Composer PHP constraints;
- PHPUnit dependencies and schemas;
- PHPStan's target version;
- PHP-CS-Fixer's migration set;
- CI runtime matrices;
- documentation.

## Acceptance criteria

Phase 1 remains accepted when:

- both Composer packages validate;
- root integration tests pass;
- framework unit tests pass;
- PHPStan reports no errors;
- PHP-CS-Fixer reports no required changes;
- Rector reports no required changes;
- Composer reports no vulnerable dependencies;
- the application autoloads the framework through its Composer package.

---
