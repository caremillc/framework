# Application Kernel

## Overview

The Careminate application kernel coordinates:

- bootstrap;
- application state;
- runtime context creation;
- runtime-specific kernel selection;
- scoped dependency resolution;
- result emission;
- cleanup;
- graceful termination;
- production container optimization.

## Purpose

The kernel provides one predictable lifecycle across web, console, worker,
serverless, desktop, and test runtimes.

## Architecture

```text
ApplicationBuilder
       |
       v
Application
       |
       +--> BootstrapSequence
       |
       +--> KernelRegistry
       |
       +--> RuntimeInterface
       |       |
       |       +--> RuntimeContext
       |       +--> RuntimeResult
       |
       +--> ScopedContainer
       |
       +--> TerminationManager

## Installation
Set-Location C:\xampp\htdocs\caremi
composer update careminate/framework --with-all-dependencies


## Configuration

Build the application with an explicit base path:
```php
<?php
use Careminate\Application\ApplicationBuilder;
use Careminate\Application\ApplicationEnvironment;

$application = ApplicationBuilder::fromBasePath(
    dirname(__DIR__),
)
    ->environment(ApplicationEnvironment::production())
    ->build();
?>
```
Environment variables and .env files are not read by the application kernel.
They are introduced through the configuration bootstrap in Phase 5.

## Basic usage
Registering a kernel
```php
<?php
$application = ApplicationBuilder::fromBasePath(dirname(__DIR__))
    ->kernel(RuntimeType::Console, ConsoleKernel::class)
    ->build();
?>
```

The kernel class may be resolved through the dependency container.

Running console lifecycle
```php
<?php
$runtime = ConsoleRuntime::fromArguments($argv);

try {
    $exitCode = $application->run($runtime);
} finally {
    $application->terminate();
}

exit($exitCode);
?>

```

## Running HTTP lifecycle
```php
<?php
$runtime = HttpRuntime::fromGlobals();

try {
    $application->run($runtime);
} finally {
    $application->terminate();
}

?>
```

This initial HTTP adapter is not a PSR-7 implementation.

## Advanced usage
Bootstrapper
```php
<?php
final class RegisterCoreServices implements BootstrapperInterface
{
    public function bootstrap(BootstrapContext $context): void
    {
        $context->container->singleton(
            ClockInterface::class,
            SystemClock::class,
        );
    }
}
?>
```

Register it:
```php
<?php
$builder->bootstrapper(
    new RegisterCoreServices(),
    priority: 100,
);
?>
```

Lower numeric bootstrap priorities execute first.

Runtime scopes

Each call to Application::run() creates a new scoped container.

A scoped service is stable during one execution but is not shared with another
request or job.

Graceful termination
$application->requestTermination();

if ($application->shouldTerminate()) {
    // Stop accepting additional work.
}

If termination is requested while a runtime is active, cleanup finishes before
application termination begins.

Termination hooks
final class CloseTelemetry implements TerminationHookInterface
{
    public function terminate(TerminationContext $context): void
    {
        // Flush and close telemetry transports.
    }
}

Higher numeric termination priorities execute first.

Optimization
$optimizer = new ApplicationOptimizer();

$report = $optimizer->optimize(
    $container,
    $application->paths(),
);

Load compiled definitions:

$compiledContainer = $optimizer->load($paths);

$application = ApplicationBuilder::fromBasePath($paths->base())
    ->container($compiledContainer)
    ->build();

A compiled container is frozen. Production bootstrap must not attempt to add
new service definitions after it is loaded.

Developer use cases

Framework developers can implement:

- HTTP kernels;
- console kernels;
- queue-worker kernels;
- serverless adapters;
- desktop runtimes;
- test runtimes;
- environment validators;
- diagnostic bootstrappers;
- shutdown and telemetry hooks.

Application developers can:

- register application bootstrappers;
- register runtime kernels;
- provide explicit environments;
- override paths;
- request graceful worker shutdown;
- compile production container definitions.

End-user use cases

End users benefit from:
- isolated request state;
- deterministic application startup;
- graceful worker shutdown;
- fewer cross-request data leaks;
- actionable startup failures;

consistent behavior across runtime types.
Contracts
ApplicationInterface
BootstrapperInterface
KernelInterface
RuntimeInterface
TerminationHookInterface
Extension points
bootstrappers;
runtime adapters;
runtime kernels;
termination hooks;
path overrides;
environment definitions;
container compilation.
Events

The application kernel emits no PSR-14 events yet. Application lifecycle events
are introduced after the Phase 6 event dispatcher exists.

Exceptions
LifecycleException
BootstrapException
KernelNotFoundException
RuntimeExecutionException
TerminationException
InvalidEnvironmentException
InvalidApplicationPathException
OptimizationException
Security
Production debug mode is rejected.
Environment values are passed explicitly.
Request data is not logged by the runtime.
Each runtime execution owns a fresh container scope.
Paths are normalized for Windows and POSIX.
Container cache files must remain outside public.
Compiled containers must be generated from trusted definitions.
Application code should not use the container as a service locator.
Performance
Application bootstrap executes once.
Kernel registries become immutable after bootstrap.
Each runtime execution allocates only one scoped-service map.
Container reflection metadata remains cached.
Optimization reports duration, memory delta, and cache size.
Runtime input is captured once rather than repeatedly reading globals.
Testing
Set-Location C:\xampp\htdocs\caremi\framework

php vendor\bin\phpunit --configuration phpunit.xml.dist tests\Unit\Application
composer analyse
composer cs:check
composer refactor:check
Troubleshooting
No kernel registered

Register a kernel for the current runtime type:

$builder->kernel(RuntimeType::Console, ConsoleKernel::class);
Application is already terminated

Create a new application instance. Terminated applications cannot be restarted.

Compiled container rejects bootstrap registrations

Move all service registration before compilation. Runtime initialization may
still run after the compiled container is loaded, but registration may not.

Cache directory unavailable

Create and permission:

C:\xampp\htdocs\caremi\bootstrap\cache
Production debugging rejected

Use:

ApplicationEnvironment::production()

Production debugging is intentionally unavailable.

Upgrade notes

Phase 3 changes the development version to 0.3.0-dev.

Container caches generated under 0.2.0-dev are invalid because cache files
record the framework version. Regenerate them after updating.

Phase 7 will specialize HTTP request and response handling.

Phase 9 will specialize console input, output, and command dispatch.

API reference
Application
bootstrap()
run()
requestTermination()
shouldTerminate()
terminate()
environment()
paths()
state()
container()
bootstrappedAt()
ApplicationBuilder
fromBasePath()
environment()
paths()
container()
bootstrapper()
kernel()
terminationHook()
build()
ApplicationOptimizer
optimize()
load()
clear()



17. Root README

# Caremi

Caremi is powered by the independently structured
`careminate/framework` package.

## Requirements

- PHP 8.4 or newer
- Composer 2
- dom
- json
- libxml
- mbstring
- tokenizer
- xml
- xmlwriter

## Installation

```powershell
$env:Path = "C:\xampp\php;$env:Path"
Set-Location C:\xampp\htdocs\caremi

php --version
composer --version
composer validate --strict
composer update
composer test
composer audit
Framework quality checks
Set-Location C:\xampp\htdocs\caremi\framework

composer validate --strict
composer update
composer quality
composer audit
Current framework capabilities

Phase 1:

engineering and repository foundation;
testing, static analysis, CI;
foundational exceptions and utilities.

Phase 2:

PSR-11 dependency container;
service lifetimes;
contextual injection;
aliases, tags, attributes, factories, lazy services;
container compilation and cache.

Phase 3:

application lifecycle;
bootstrap sequence;
runtime abstraction;
console and initial HTTP lifecycles;
per-runtime scopes;
graceful termination;
application environment, paths, and state;
production optimization reporting.

Service providers and modules begin in Phase 4.


# Commands

The framework version changed, so regenerate the Composer metadata and any old compiled container cache:

```powershell
$env:Path = "C:\xampp\php;$env:Path"

Set-Location C:\xampp\htdocs\caremi\framework

composer validate --strict
composer update
composer dump-autoload --optimize
composer test
composer analyse
composer cs:check
composer refactor:check
composer audit

Run only Phase 3 tests:

php vendor\bin\phpunit --configuration phpunit.xml.dist tests\Unit\Application

Update the root application:

Set-Location C:\xampp\htdocs\caremi

composer update careminate/framework --with-all-dependencies
composer dump-autoload --optimize
composer test
composer audit

Create the production cache directory when testing optimization:

New-Item -ItemType Directory -Force C:\xampp\htdocs\caremi\bootstrap\cache
Security review
Production debug mode cannot be enabled.
The kernel does not load environment variables directly.
Each runtime gets an isolated dependency scope.
Request input is captured but never automatically logged.
Runtime failures retain their original exception.
Cleanup failures are reported rather than silently discarded.
Application paths are explicit and normalized.
Compiled cache remains outside the document root.
Cache generation requires a pre-existing writable directory.
Termination remains idempotent.
Performance review
Bootstrap runs once per application instance.
Kernel registration freezes after bootstrap.
Reflection caching remains owned by the Phase 2 container.
Each request/job allocates one scoped instance array.
Raw runtime input is captured once.
Container optimization measures time, memory delta, and cache bytes.
No filesystem discovery or directory scanning occurs during runtime execution.
Acceptance criteria
 PHP 8.4 or 8.5 is active.
 Both Composer packages validate.
 Framework tests pass.
 Root integration tests pass.
 PHPStan level max reports zero errors.
 PHP-CS-Fixer reports no changes.
 Rector dry-run reports no changes.
 Bootstrappers execute once in priority order.
 Invalid bootstrap state transitions fail.
 Console runtime completes handle, emit, and cleanup.
 HTTP runtime completes handle, emit, and cleanup.
 Every runtime receives an independent dependency scope.
 Kernel and runtime cleanup run after failure.
 Termination hooks run in reverse priority order.
 Graceful termination waits for active runtime cleanup.
 Production debugging is rejected.
 Windows application paths normalize correctly.
 Container optimization creates a measurable report.
 Old 0.2.0-dev container caches are regenerated.
 Composer audit reports no vulnerable dependencies.
