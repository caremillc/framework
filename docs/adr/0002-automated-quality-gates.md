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

## Installation and verification

Open PowerShell:
```PowerShell
$env:Path = "C:\xampp\php;$env:Path"

Set-Location C:\xampp\htdocs\caremi\framework

php --version
composer --version
composer validate --strict
composer update
composer quality
composer audit
```

Then verify application-to-framework integration:

Set-Location C:\xampp\htdocs\caremi
```PowerShell
composer validate --strict
composer update
composer test
composer audit
```

After the first successful root installation, commit:

C:\xampp\htdocs\caremi\composer.lock

Do not manually create or copy a composer.lock; Composer generates it from the actual dependency resolution. Composer recommends committing lock files for applications so installations remain reproducible. Composer lock-file guidance

---
