# ADR-0009 — Console Execution and Read-Only Route Inspection

**Status:** Proposed  
**Framework:** Careminate 0.1.0-dev  
**Scope:** Console command dispatch, diagnostics, route inspection and application CLI integration

## Context

Careminate needs a predictable command-line entry point that works in a direct framework checkout and in an application using the framework through Composer.

CLI commands must preserve exit codes, avoid exposing sensitive values, and work without unexpectedly booting the HTTP application.

The application skeleton also requires a way to list real registered routes without invoking handlers or changing Router state.

## Decision

Use `ConsoleApplication` as the command dispatcher and `ConsoleResult` as the immutable command-output contract.

Built-in help and version commands remain available independently of optional command implementations.

The CLI launchers own output emission and top-level exception handling.

Use `AboutCommand` for a fixed set of safe runtime diagnostics.

Expose an immutable metadata snapshot through `Router::registeredRoutes()`. Use `RouteListCommand` to render that metadata as a table or JSON.

Use `LazyRouteListCommand` in the application launcher to validate arguments before loading application route declarations.

The standalone framework launcher uses an empty Router because it does not own application routes.

The application launcher uses a shared route bootstrap that can also be used by the HTTP Kernel.

## Error-handling contract

- Success returns exit code `0`.
- Unsupported commands and arguments return `64`.
- Internal failures or unsafe route metadata return `70`.
- Missing Composer autoload configuration returns `78`.
- Successful results use stdout.
- Errors use stderr.
- Unexpected exception details are not exposed in public CLI output.

Command implementations should not write directly to global output streams.

## Security considerations

Command names and descriptions are constrained by the console registry.

Untrusted command arguments are not echoed through usage errors.

Route metadata is validated before terminal or JSON rendering.

The route-list command does not execute HTTP handlers, middleware or route dispatch.

Invalid route-list options do not load application route declarations.

Application route files are trusted executable code. Lazy loading reduces unnecessary execution but does not provide isolation.

The `about` command intentionally limits its output to selected public runtime facts.

## Alternatives considered

**Boot the entire application for every CLI invocation:** Rejected because help and version do not require application bootstrapping and should remain available even when application configuration is broken.

**Use reflection to inspect Router internals:** Rejected in favor of an explicit, typed public metadata snapshot.

**Duplicate route definitions for HTTP and CLI:** Rejected because separate registries would drift.

**Print exceptions directly to stderr:** Rejected for the public CLI boundary because exception messages may include internal paths or sensitive values.

**Introduce a large third-party console framework immediately:** Deferred because the first-release command surface is small. Dependency evaluation can be revisited when additional command requirements justify it.

## Consequences

The console remains intentionally lightweight.

Command dependencies can be initialized only when their command is invoked.

The Router maintains a small additional metadata index for method-specific route names.

Framework and application launchers share command components but have different route-loading responsibilities.

Application bootstrap failures remain possible for valid route-list invocations and are handled at the launcher boundary.

Table column alignment for multibyte Unicode is approximate; JSON output preserves supported Unicode data.

## Compatibility

The existing `ConsoleApplication::run()` and `ConsoleResult` contracts remain unchanged.

The Router's dispatch behavior, named URL generation, automatic HEAD behavior and OPTIONS handling remain independent of route inspection.

Applications must register CLI commands explicitly. Existing HTTP route registration remains the source of route metadata.

## Verification requirements

Before accepting this ADR:

1. Framework unit, integration, static-analysis and smoke gates pass.
2. Framework CLI packaging and exit-code tests pass.
3. Application-skeleton routes are listed correctly by the application CLI.
4. Application HTTP and CLI route snapshots agree.
5. Windows XAMPP execution passes.
6. Linux CLI execution and executable permissions are verified.
7. PHP 8.4 compatibility is verified.
8. Dependency audit results are reviewed.

**Status remains Proposed until the applicable checks have recorded evidence.**