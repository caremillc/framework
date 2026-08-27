# Service Providers and Modules

## Overview

A Careminate module is an application boundary with one public entry point.
The module definition declares dependencies, capabilities, version information,
and service providers.

Runtime metadata is defined in PHP. Composer metadata is used only to discover
modules distributed as packages.

## Lifecycle

The framework performs these operations:

1. Discover local and Composer modules.
2. Apply enabled and disabled selections.
3. Validate module names and versions.
4. Build the capability registry.
5. Validate required dependencies.
6. Resolve the dependency graph.
7. Reject dependency cycles.
8. Register all enabled modules.
9. Boot all enabled modules.

All module registration completes before any module boots.

## Module definition

```php
return ModuleDefinition::named('billing')
    ->version('1.0.0')
    ->requires(UsersModule::class, '^1.0')
    ->optionallyRequires(AuditModule::class, '^2.0')
    ->requiresCapability(UserRepository::class)
    ->optionallyUses(NotificationSender::class)
    ->provides(PaymentGateway::class)
    ->provider(BillingServiceProvider::class);
```
---


Definitions are immutable. Each fluent call returns a new definition.

## Required module dependencies

A required dependency must:

Be registered or discovered
Be enabled
Satisfy its version constraint
Boot before the consuming module

Startup fails if any condition is violated.

Optional module dependencies

An optional dependency may be absent or disabled. If it is present and enabled,
its version must satisfy the declared constraint and it boots before the
consumer.

Capabilities

A capability represents a framework or application contract supplied by one
module.

Exactly one enabled module may provide a capability. Multiple providers are
rejected because implicit provider selection would make runtime behavior depend
on discovery order.

Module disabling

Modules must be disabled before application bootstrap.

A disabled module contributes no:

Service providers
Services
Aliases
Tags
Contextual bindings
Boot behavior

Disabling does not delete module data.

An enabled module cannot require a disabled module.

Service ownership

Modules do not receive the mutable application container. They register services
through a module-owned service registry.

A module may replace only services it previously registered. It cannot take
ownership of another module's service.

Compiled containers

When ModuleManager::compiled(true) is selected, registration callbacks still
run to rebuild ownership diagnostics, but container mutation is skipped.

The compiled container must have been built from the same module fingerprint and
module plan.

Composer discovery

A package declares modules through its composer.json:

{
    "extra": {
        "careminate": {
            "modules": [
                "Vendor\\Package\\PackageModule"
            ]
        }
    }
}

Careminate reads the Composer-generated vendor/composer/installed.json file.
It does not scan arbitrary source directories.

Module cache

The cache stores:

Framework version
Cache schema
Module definition fingerprint
Enabled module order
Disabled module names

The cache is rejected when the framework version, module definitions, dependency
selection, or module list changes.

Diagnostics

ModuleManager::diagnostics() returns each module's:

Name
Entry-point class
Current status
Lifecycle message
Boot position

ModuleManager::ownership()->snapshot() exposes recorded module service
ownership.

ModuleManager::capabilities()->all() exposes capability ownership.


---

# 17. Windows/XAMPP commands

Run from Command Prompt:

```bat
cd C:\xampp\htdocs\caremi

composer update careminate/framework composer/semver --with-all-dependencies

composer dump-autoload

php bin\cache-modules.php

Run framework verification:

cd C:\xampp\htdocs\caremi\framework

composer install

vendor\bin\phpunit

vendor\bin\phpstan analyse --memory-limit=1G

vendor\bin\php-cs-fixer fix --dry-run --diff

Run application tests:

cd C:\xampp\htdocs\caremi

vendor\bin\phpunit

vendor\bin\phpstan analyse --memory-limit=1G

Clear the module cache after changing module definitions:

del C:\xampp\htdocs\caremi\bootstrap\cache\modules.json

Then rebuild it:

cd C:\xampp\htdocs\caremi

php bin\cache-modules.php
18. Security and performance improvements
| Improvement                 | Result                                                                                  |
| --------------------------- | --------------------------------------------------------------------------------------- |
| Controlled service registry | Modules cannot mutate the application container directly                                |
| Service ownership           | Cross-module service replacement is rejected                                            |
| Immutable definitions       | Metadata cannot change during dependency planning                                       |
| Composer Semver validation  | Invalid versions and constraints fail immediately                                       |
| Deterministic sorting       | Boot behavior does not depend on discovery order                                        |
| JSON module cache           | Cache loading does not execute PHP                                                      |
| Atomic cache replacement    | Readers never observe a partially written cache                                         |
| Composer manifest discovery | No recursive or arbitrary source scanning                                               |
| Disabled-module filtering   | Disabled code never registers or boots                                                  |
| Cached fingerprint          | Stale module plans fail explicitly                                                      |
| Two-stage lifecycle         | Every registration finishes before runtime boot integration                             |
| Actionable exceptions       | Missing, disabled, cyclic, incompatible, and duplicate dependencies are distinguishable |



Phase 4 is complete when:

 Local modules are discovered.
 Composer package modules are discovered.
 Duplicate module names are rejected.
 Required module dependencies are validated.
 Optional missing or disabled modules are ignored.
 Present optional dependencies respect version constraints.
 Required capabilities are validated.
 Duplicate capability providers are rejected.
 Circular dependencies show the complete cycle.
 Boot ordering is deterministic.
 Every registration completes before boot starts.
 Disabled modules never register services or boot.
 Enabled modules cannot require disabled modules.
 Modules cannot replace services owned by other modules.
 Cache files are atomically generated.
 Stale caches are rejected.
 Diagnostics show module status and boot position.
 PHPUnit passes.
 PHPStan passes at maximum configured level.
 PHP CS Fixer reports no violations.
