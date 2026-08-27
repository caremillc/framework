# Dependency Container

## Overview

Careminate provides a PSR-11-compatible dependency-injection container with
bindings, lifetimes, contextual injection, attributes, factories, lazy
services, diagnostics, compilation, and cache loading.

## Purpose

The container constructs framework and application services while keeping
their dependencies explicit and testable.

Application services should receive dependencies through constructors.
Direct container lookup should remain limited to framework integration,
factories, bootstrapping, and diagnostic tooling.

## Architecture

The main components are:

- `ContainerInterface` — PSR-11 resolution plus tags, scopes, and diagnostics.
- `ContainerRegistryInterface` — controlled service registration.
- `Container` — root registration and resolution implementation.
- `ScopedContainerInterface` — independent scoped-service cache.
- `FactoryInterface` — container-aware complex creation.
- `ContainerCompiler` — definition validation and cache generation.
- `ContainerCache` — executable PHP cache writing and loading.

## Installation

The container is part of `caremillc/framework`.

```powershell
Set-Location C:\xampp\htdocs\caremi
composer update
Configuration

No configuration file is required.

Register entries during application bootstrap:

use Careminate\Container\Container;

$container = new Container();

$container->bind(
    PaymentGateway::class,
    StripePaymentGateway::class,
);
Basic usage
Autowiring
final class InvoiceService
{
    public function __construct(
        private PaymentGateway $gateway,
    ) {
    }
}

$container->bind(
    PaymentGateway::class,
    StripePaymentGateway::class,
);

$service = $container->get(InvoiceService::class);
Singleton
$container->singleton(ConnectionPool::class);

$first = $container->get(ConnectionPool::class);
$second = $container->get(ConnectionPool::class);

// $first === $second
Scoped service
$container->scoped(RequestContext::class);

$scope = $container->createScope('http-request');

try {
    $context = $scope->get(RequestContext::class);
} finally {
    $scope->close();
}
Aliases
$container->bind(
    CacheRepository::class,
    RedisCacheRepository::class,
);

$container->alias('cache', CacheRepository::class);

$cache = $container->get('cache');
Advanced usage
Contextual service binding
$container
    ->when(AuditService::class)
    ->needs(LoggerInterface::class)
    ->give(AuditLogger::class);
Primitive binding

Prefix constructor parameter names with $:

$container
    ->when(DatabaseConnection::class)
    ->needs('$dsn')
    ->give('mysql:host=127.0.0.1;dbname=caremi');
Tagged services
$container->singleton(EmailHandler::class);
$container->singleton(SmsHandler::class);

$container->tag(
    [EmailHandler::class, SmsHandler::class],
    'notification.handlers',
);

Inject the collection:

use Careminate\Container\Attribute\Tagged;

final class NotificationDispatcher
{
    public function __construct(
        #[Tagged('notification.handlers')]
        private iterable $handlers,
    ) {
    }
}
Named injection
use Careminate\Container\Attribute\Inject;

$container->instance('application.name', 'Caremi');

final class ApplicationMetadata
{
    public function __construct(
        #[Inject('application.name')]
        public readonly string $name,
    ) {
    }
}

Factory service
final class GatewayFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): PaymentGateway
    {
        return new StripePaymentGateway(
            $container->get(HttpClient::class),
        );
    }
}

$container->factory(
    PaymentGateway::class,
    GatewayFactory::class,
);
Lazy service
use Careminate\Container\Attribute\Lazy;

#[Lazy]
final class ExpensiveClient
{
    public function __construct(
        private HttpTransport $transport,
    ) {
    }
}

Or register laziness explicitly:

$container->lazy(
    ExpensiveClient::class,
    ExpensiveClient::class,
);

PHP initializes the real object when its state is first observed or modified.

Compilation
use Careminate\Container\Compiler\ContainerCompiler;

$compiler = new ContainerCompiler();

$compiler->compile(
    $container,
    __DIR__ . '/../bootstrap/cache/container.php',
);

Load it in production:

$container = $compiler->load(
    __DIR__ . '/../bootstrap/cache/container.php',
);

Loaded compiled containers are frozen.

Developer use cases

Framework developers can use the container for:

kernel dependencies;
service-provider registrations;
module-owned services;
request and job scopes;
handler collections;
diagnostic commands;
production definition caching.

Application developers can use it for:

repository implementations;
payment gateways;
external API clients;
application services;
domain-policy implementations;
tenant or request contexts.
End-user use cases

Users benefit indirectly through:

predictable request isolation;
faster production startup;
replaceable infrastructure integrations;
clearer application failures;
fewer cross-request state leaks;
testable business services.
Contracts
ContainerInterface
ScopedContainerInterface
ContainerRegistryInterface
ContextualBindingBuilderInterface
FactoryInterface
Extension points

Supported extension points are:

interface-to-class bindings;
explicit factories;
factory-service classes;
contextual bindings;
aliases;
tags;
service attributes;
independent scopes.

Do not subclass the concrete container; it is final.

Events

The container emits no events in Phase 2 because the event dispatcher is not
implemented until Phase 6.

Exceptions
NotFoundException — missing service identifier.
InvalidDefinitionException — invalid, duplicate, or reserved registration.
CircularDependencyException — circular constructor or alias dependency.
UnresolvableDependencyException — constructor or factory failure.
ScopeException — invalid scoped-service access.
FrozenContainerException — mutation of compiled container.
ContainerCompilationException — non-compilable or invalid definition.
ContainerCacheException — cache read or write failure.

Container-created exceptions implement PSR-11 container exception contracts.

Security
Cache files must reside outside the public document root.
Cache directories must not be writable by the web server in production.
Treat container cache files as executable PHP.
Do not place unencrypted credentials into compiled primitive bindings.
Never compile untrusted input.
Never let request data choose service identifiers.
Close scopes to prevent request or tenant state retention.
Do not use the container as an application-level service locator.
The compiled cache never uses unserialize().
Performance

Singleton and scoped lookups use direct array access.

Reflection metadata is cached in memory after first inspection.

Compilation avoids repeating registration logic but does not yet generate
ahead-of-time constructor invocation code.

Native PHP lazy proxies defer expensive initialization.

Recommended benchmark scenarios:

10,000 transient resolutions;
10,000 singleton resolutions;
nested autowiring depth of 10;
100 tagged services;
1,000 independent scope lifecycles;
compiled versus non-compiled startup.
Testing
Set-Location C:\xampp\htdocs\caremi\framework

composer test
composer analyse
composer cs:check
composer refactor:check

Run only container tests:

php vendor\bin\phpunit --configuration phpunit.xml.dist tests\Unit\Container
Troubleshooting
Entry not found

Bind an interface to a concrete implementation:

$container->bind(
    RepositoryInterface::class,
    DatabaseRepository::class,
);
Primitive cannot be resolved

Add a contextual binding using the constructor parameter name:

$container
    ->when(Service::class)
    ->needs('$endpoint')
    ->give('https://internal.example');
Scoped service cannot resolve

Create a scope and resolve through it:

$scope = $container->createScope('request');
$service = $scope->get(RequestService::class);
Container cannot compile a closure

Replace the closure with a class implementing FactoryInterface, then register
its class name as the factory service.

Compiled container is frozen

Rebuild the mutable registration container and regenerate the cache. Do not
modify the loaded compiled instance.

Upgrade notes

Phase 2 introduces the public container contract.

Before Careminate 1.0, minor compatibility changes remain possible. After 1.0,
public contract changes require the documented deprecation process.

The PHP 8.4 baseline is mandatory because transparent lazy services use native
lazy objects.

Compiled cache files are version-specific and must be regenerated after a
framework version change.

API reference
ContainerRegistryInterface
bind()
replace()
singleton()
scoped()
lazy()
factory()
instance()
alias()
tag()
when()
ContainerInterface
get()
has()
tagged()
createScope()
diagnose()
ScopedContainerInterface
scopeName()
close()
isClosed()
ContainerCompiler
compile()
load()

