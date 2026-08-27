# Changelog

All notable changes to the Careminate framework are documented in this file.

## [Unreleased]

### Added

- PSR-11-compatible dependency-injection container.
- Public container registration and resolution contracts.
- Transient, singleton, and scoped service lifetimes.
- Independent scoped containers for request and worker isolation.
- Contextual service and primitive bindings.
- Explicit aliases and tagged-service collections.
- Reflection-based constructor autowiring.
- `Inject`, `Tagged`, and `Lazy` dependency attributes.
- Callable, object, and container-managed factory services.
- Native PHP 8.4 lazy proxies.
- Circular dependency and alias-cycle detection.
- Resolution-path diagnostics.
- Exportable container definition snapshots.
- Executable PHP container cache.
- Frozen compiled containers.
- Container unit and application integration tests.

### Changed

- Minimum supported PHP version corrected to PHP 8.4.
- PHPUnit development baseline updated to PHPUnit 12.5.
- PHPStan analysis target corrected to PHP 8.4.
- Framework development version updated to `0.2.0-dev`.
---

