# Contributing

## Runtime requirements

Careminate requires PHP 8.2 or newer and Composer 2.

## Development installation

```powershell
$env:Path = "C:\xampp\php;$env:Path"
Set-Location C:\xampp\htdocs\caremi\framework

composer validate --strict
composer update
```
---

## Required checks

Run the complete quality pipeline before submitting a change:
```powershell
composer quality
composer audit
```
---

The quality pipeline executes:
1. PHPUnit tests.
2. PHPStan at level max.
3. PHP-CS-Fixer in dry-run mode.
4. Rector in dry-run mode.
5. Applying automated changes

Apply coding-standard corrections:
```powershell
composer cs:fix
```

Apply reviewed Rector transformations:
```powershell
composer refactor
```

Always inspect automated changes and rerun composer quality.

Engineering rules
- Every PHP source file must declare strict types.
- Follow PSR-12.
- Use constructor injection for object dependencies.
- Avoid service locators and hidden global state.
- Do not create dynamic object properties.
- Use precise domain-specific exceptions.
- Declare classes final unless inheritance is explicitly part of the design.
- Add tests for success paths, failure paths, and relevant edge cases.
- Do not introduce network-service requirements into unit tests.
- Do not suppress PHPStan errors without a documented architectural reason.
- Record material architecture decisions in docs/adr.

---