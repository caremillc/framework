# Careminate — Command-Line Interface

**Framework:** Careminate 0.1.0-dev  
**Minimum PHP:** 8.4  
**Development:** Windows XAMPP / Git Bash  
**Deployment target:** Linux  
**Status:** Implemented CLI commands; packaging and cross-platform verification pending

## Overview

Careminate provides a small first-party command-line interface through the `careminate` executable.

The framework CLI handles command dispatch, runtime diagnostics and read-only route inspection. The application skeleton uses the same console components with application-specific route loading.

Commands are designed to return explicit exit codes and keep standard output separate from standard error.

## 1. Running the CLI

### Framework checkout

From the framework directory:

```bash
cd /c/xampp/htdocs/caremi/framework

php careminate --help
php careminate --version
php careminate about
php careminate route:list
```

### Application skeleton

From the application root:

```bash
cd /c/xampp/htdocs/caremi

php careminate --help
php careminate route:list
php careminate route:list --format=json
```

Both directories contain a file named `careminate` without a `.php` extension. They serve different responsibilities.

The framework launcher does not load application route files.

The application launcher uses `bootstrap/routes.php` to load application-owned route declarations for valid `route:list` requests.

## 2. Supported commands

| Command | Purpose |
|---|---|
| `php careminate` | Display general help |
| `php careminate help` | Display general help |
| `php careminate --help` | Display general help |
| `php careminate help about` | Display help for the `about` command |
| `php careminate help route:list` | Display help for `route:list` |
| `php careminate version` | Display framework version |
| `php careminate --version` | Display framework version |
| `php careminate about` | Display selected runtime facts |
| `php careminate about --format=json` | Display runtime facts as JSON |
| `php careminate route:list` | List registered routes |
| `php careminate route:list --format=json` | List registered routes as JSON |

Both `about` and `route:list` support `--format=table` explicitly. Table format is their default.

Commands not listed here are not part of the current supported CLI contract.

## 3. Version command

```bash
php careminate --version
```

Example:

```text
Careminate 0.1.0-dev
```

The value is obtained from `Careminate\Foundation\Framework::version()`.

The CLI does not maintain an independent hardcoded version number.

## 4. Route listing

```bash
php careminate route:list
```

For a configured application, the table contains:

```text
METHOD  PATH             NAME
GET     /                home
GET     /api/items/{id}  api.items.show
GET     /api/status      api.status
GET     /health          health
```

These rows are examples from the application-skeleton route definitions. Actual output reflects routes registered by the current application.

JSON output uses a list of objects:

```json
[
  {
    "method": "GET",
    "path": "/health",
    "name": "health"
  }
]
```

The actual command emits compact JSON on one line.

### Route-listing guarantees

- Only explicitly registered methods are listed.
- Rows are sorted by path and method.
- Names belong to the exact path and method.
- Route handlers are not executed during inspection.
- The snapshot does not freeze the Router.
- The route-list response contains only `method`, `path` and `name`.
- Unsupported command options are rejected before application route bootstrap is executed.
- Malformed UTF-8 and terminal-control metadata are rejected rather than rendered.

### Framework versus application output

From the framework package directory:

```bash
php careminate route:list --format=json
```

Expected with the standalone framework launcher:

```json
[]
```

This is intentional. The package itself does not define application routes.

From the application skeleton, the same command lists application-owned routes loaded through the shared route bootstrap.

### Important trust boundary

Application route declarations are trusted PHP code.

Loading `bootstrap/routes.php` can execute PHP statements in the application route file. Lazy loading prevents this for unrelated commands and unsupported options, but it does not turn the route file into a security sandbox.

Route registration should remain free from unnecessary database writes, network requests and other side effects.

## 5. Runtime diagnostics

```bash
php careminate about
php careminate about --format=json
```

The command reports:

- Framework name and version
- PHP version and framework minimum PHP version
- Runtime-support status
- PHP SAPI
- Operating-system family
- Architecture width
- Availability of the `json`, `mbstring` and `pdo` extensions

The extension indicators describe current availability. They do not independently establish that every extension is mandatory.

`about` does not expose arbitrary process environment variables, `.env` values, credentials, filesystem paths, PHP configuration paths or connection strings.

The command is informational. A successful report does not by itself establish that an application is ready for production.

## 6. Exit codes

| Exit code | Meaning |
|---|---|
| `0` | Command succeeded |
| `64` | Invalid command or command usage |
| `70` | Internal console error or route metadata cannot be safely rendered |
| `78` | Composer autoload configuration missing |

Registered application commands can return additional valid exit codes through `ConsoleResult`.

### Standard output and standard error

Successful command results are written to stdout.

Usage errors, configuration failures and unexpected console failures are written to stderr.

Unknown command names and invalid arguments are not reproduced in public errors. Unexpected exceptions do not print stack traces or internal paths through the CLI launcher.

Example:

```bash
php careminate route:list --format=xml
echo "Exit: $?"
```

Expected:

```text
Invalid route:list arguments.
Usage: php careminate route:list [--format=table|json]
Exit: 64
```

## 7. Composer installation and binary packaging

The framework Composer manifest includes the binary declaration:

```json
{
  "bin": [
    "careminate"
  ]
}
```

This is a top-level entry within the existing `composer.json`, not a replacement for its other properties.

When the framework is installed as a Composer dependency and the package is configured correctly, Composer exposes its executable in the installed project's `vendor/bin` directory.

The current archived project metadata uses the package identifier `caremillc/framework`. Preserve the currently installed package identity until a deliberate package-naming migration is completed.

Before publication, verify package availability and ownership. The presence of a local Composer package does not prove registry ownership or availability.

### Windows

Use PHP explicitly:

```bash
php careminate --version
```

The Windows development environment does not require direct execution of a Unix shebang.

### Linux

Use either:

```bash
php careminate --version
```

or, when the executable bit and shebang are correctly packaged:

```bash
./careminate --version
```

The launcher must use LF line endings and start with:

```text
#!/usr/bin/env php
<?php
```

Its executable mode should be tracked as `100755` in Git.

### PHP compatibility

Careminate's declared minimum is PHP 8.4.

Passing on PHP 8.5.11 does not establish PHP 8.4 compatibility. Both runtimes require verification before the supported runtime matrix can be accepted.

## 8. Troubleshooting

### Composer autoload missing

A standalone launcher without an available Composer autoloader returns exit code `78`.

From the appropriate repository:

```bash
composer install
composer dump-autoload -o
```

Verify that the expected `vendor/autoload.php` exists.

### Unknown command

Run:

```bash
php careminate --help
```

Unknown commands return exit code `64`.

### No registered routes

Confirm whether the CLI was executed from the framework package or the application root.

The standalone framework package intentionally reports an empty route list.

In the application root, confirm that `bootstrap/routes.php` exists and that it constructs a Router from the application's trusted route declarations.

### Internal console error

Unexpected exceptions return exit code `70` with a sanitized message.

Inspect application and test logs in a trusted development environment. Do not change the public CLI to expose exception messages in production.

### Unsafe route metadata

If `route:list` reports that route metadata cannot be displayed safely, inspect route declarations for malformed UTF-8 or Unicode terminal-control characters.

The command intentionally does not render the rejected value.

### Application and framework return different route lists

This is expected when application routes are configured.

The framework launcher uses an empty package Router; the application launcher loads the application's route declarations.

## 9. Verification

Framework regression suite:

```bash
cd /c/xampp/htdocs/caremi/framework

composer validate --strict
composer analyse
composer check
composer audit --no-interaction
```

Application-skeleton regression suite:

```bash
cd /c/xampp/htdocs/caremi

composer validate --strict
composer analyse
composer check
composer audit --no-interaction
```

`composer audit` needs valid dependency metadata and may require network access. A failed lookup is not a successful audit.

Verify framework and application checks independently. A passing framework suite does not demonstrate that the application skeleton boots correctly.

## 10. Current scope and limitations

Careminate 0.1 development currently exposes help, version, runtime diagnostics and route listing.

Database migrations, class generators, queue workers, schedulers and cache-management commands are not provided by this Day 21 CLI increment.

The command registry and console results establish a foundation for future commands without implying those commands are already implemented.

The initial operating model remains conventional request-per-process PHP. Long-running workers and advanced CLI lifecycle handling are deferred.

**Documentation status:** Draft until application-skeleton and Linux/PHP 8.4 verification evidence is recorded.