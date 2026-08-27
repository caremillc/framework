# ADR 0001: Framework as a local Composer package

- Status: Accepted
- Date: 2026-08-26

## Context

Careminate must evolve as a reusable framework without becoming inseparably
coupled to the first Caremi application.

Placing framework source directly in the application autoload map would allow
application code to depend on undeclared internals and would hide missing
package dependencies.

## Decision

The repository contains two Composer packages:

- the root `caremillc/caremi` application;
- the nested `caremillc/framework` library.

The root application consumes the framework through a Composer path
repository with the development version `0.1.0-dev`.

The framework owns its production dependencies, development tooling, unit
tests, documentation, and PSR-4 namespace.

## Consequences

### Positive

- Package dependencies remain explicit.
- Framework autoloading is tested through Composer.
- The framework can later move to its own repository or package registry.
- Application and framework tests remain independently executable.
- Application code cannot accidentally autoload undeclared framework paths.

### Negative

- Developers run Composer in both package roots.
- The application must refresh its local package when package metadata changes.
- Two test entry points must be maintained.

## Follow-up

Before a stable public release:

- replace the explicit path-development version with tagged releases;
- publish the framework through an approved Composer repository;
- introduce a supported-version policy.



36. C:\xampp\htdocs\caremi\framework\docs\adr\0002-automated-quality-gates.md
# ADR 0002: Automated quality gates from the first phase

- Status: Accepted
- Date: 2026-08-26

## Context

Retrofitting static analysis, formatting, tests, and automated refactoring
after framework APIs stabilize creates avoidable migration work and permits
inconsistent conventions to spread across subsystems.

The framework must remain maintainable as dependency injection, HTTP,
routing, persistence, events, and observability are introduced.

## Decision

Every framework change must pass:

- PHPUnit;
- PHPStan at level `max`;
- PHP-CS-Fixer in dry-run mode;
- Rector in dry-run mode;
- Composer validation;
- Composer security audit.

CI executes runtime tests across supported PHP versions and executes the
complete quality pipeline against the minimum PHP version.

No PHPStan baseline is introduced during Phase 1.

## Consequences

### Positive

- Type defects are detected before runtime.
- Formatting remains deterministic.
- New PHP improvements are identified continuously.
- Runtime-version incompatibilities are detected early.
- Later framework phases inherit a measurable definition of completion.

### Negative

- Initial development requires more explicit types and test fixtures.
- Tool upgrades may require reviewed configuration updates.
- CI performs several independent jobs.

## Tool-upgrade policy

Tool constraints are updated intentionally.

After a tool update:

1. inspect its changelog;
2. run all quality commands;
3. review generated suggestions;
4. update configuration only when the behavior is understood;
5. record material policy changes in a new ADR.
