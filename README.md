# Careminate

Careminate is a modular PHP framework under incremental development.

It currently includes engineering foundations and a dependency container
with explicit values, factories, singletons, aliases, and dependency-path
diagnostics.

It is not yet a runnable web framework or a stable production release.

## Project identity

| Item | Value |
| --- | --- |
| Framework package | caremillc/framework |
| Framework namespace | Careminate\ |
| Application namespace | App\ |
| Supported PHP branches | 8.4 and 8.5 |

The application consumes framework/ through a Composer path repository.

## Implemented capabilities

- Separate application and framework Composer packages.
- Framework and PSR-compatible container exception contracts.
- Explicit values, transient factories, and lazy singletons.
- Aliases to existing entries.
- PSR-11 lookup methods.
- Recursive-resolution detection and dependency paths.
- Package and source-boundary tests.
- PHPUnit, PHPStan max, and PSR-12 formatting.
- Architecture and versioning documentation.
- Windows/Linux CI configuration for PHP 8.4 and PHP 8.5.

Autowiring, scopes, contextual bindings, tags, and compilation remain
future units.

See docs/progress.md for verification evidence and accepted deferrals.

## Installation

Use Composer 2 and PHP 8.4 or PHP 8.5.

The framework requires psr/container:^2.0.

From a checkout with the current committed lock:

```powershell
composer install
composer check-platform-reqs
composer check
```

Stop if a command fails.

Older Phase 1 checkouts must apply the Phase 2A dependency change first.

Use PHP 8.4 for deliberate dependency resolution. Do not manually edit
lock entries.

## Local development

Current project directory:

```text
C:\xampp\htdocs\careminate
```

Refresh a mirrored framework installation after source changes:

```powershell
composer reinstall caremillc/framework
composer dump-autoload
composer check
```

## Quality commands

| Command | Purpose |
| --- | --- |
| composer validate:packages | Validate both manifests |
| composer test | Run tests |
| composer analyse | Run PHPStan max |
| composer cs:check | Check formatting |
| composer cs:fix | Apply formatting |
| composer check | Run configured quality checks |

## Continuous integration

The workflow covers Ubuntu 24.04 and Windows Server 2022 with PHP 8.4
and PHP 8.5.

Jobs install the committed lock, check platform requirements, and run
composer check.

GitHub Actions must be enabled.

## Documentation

- [Container](docs/container.md)
- [Container exception contracts](docs/architecture/0004-container-contracts.md)
- [Framework exceptions](docs/exceptions.md)
- [Contribution workflow](CONTRIBUTING.md)
- [Quality checks](docs/development/quality-checks.md)
- [Versioning](docs/versioning.md)
- [Package boundaries](docs/architecture/0001-package-boundaries.md)
- [Metadata boundaries](docs/architecture/0002-package-metadata-boundaries.md)
- [Source boundaries](docs/architecture/0003-framework-source-boundaries.md)
- [Phase 1 verification](docs/development/phase-1-verification.md)
- [Progress](docs/progress.md)

## Release status

The initial manifests declare proprietary pending a distribution-license
decision.

No stable release or open-source license grant is implied.