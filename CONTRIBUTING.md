# Contributing

## Runtime requirements

Careminate requires PHP 8.4 or newer and Composer 2.

## Development installation

```powershell
$env:Path = "C:\xampp\php;$env:Path"
Set-Location C:\xampp\htdocs\caremi\framework

composer validate --strict
composer update
```
---

## Required checks
```powershell
composer quality
composer audit
```

## The quality pipeline executes:

- PHPUnit 12 tests.
- PHPStan at level max.
- PHP-CS-Fixer in dry-run mode.
- Rector in dry-run mode.
- Container contribution rules
- Public application code should depend on container contracts.
- Avoid injecting the container into application services.
- Use constructor injection for application dependencies.
- Use explicit contextual bindings for primitive values.
- Duplicate service registrations are rejected.
- Use replace() only when an override is deliberate.
- Scoped services must not escape their scope.
- Close long-running request and job scopes in finally blocks.
- Callable and object factories are runtime-only and cannot be compiled.
- Compilable services must use class names, factory-service class names, or scalar and array values.
- Do not cache secret values in a web-writable directory.
- Do not edit generated container-cache files manually.


## Engineering rules
- Every PHP source file must declare strict types.
- Follow PSR-12.
- Avoid service locators in application code.
- Do not create dynamic object properties.
- Use precise framework exceptions.
- Add success, failure, lifecycle, and regression tests.
- Do not suppress PHPStan errors without a documented reason.
- Record material architectural changes in docs/adr.

---
