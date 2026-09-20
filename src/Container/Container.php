<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Container\Exception\ContainerException;
use Careminate\Container\Exception\ContextBindingException;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\InvalidTagException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Exception\ScopeStateException;
use Careminate\Container\Internal\AutowireFactory;
use Careminate\Container\Internal\LazyClass;
use Careminate\Container\Internal\ResolutionAccessPolicyInterface;
use Careminate\Container\Internal\ResolutionExecutionContext;
use Careminate\Container\Internal\ServiceLifetime;
use Closure;
use Psr\Container\ContainerInterface;
use stdClass;
use Throwable;

/**
 * Container for explicit values, factories, aliases, and autowired services.
 *
 * @api
 */
final class Container implements ContainerInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $entries = [];

    /**
     * @var array<string, array{
     *     factory: Closure(ContainerInterface): mixed,
     *     lifetime: ServiceLifetime
     * }>
     */
    private array $definitions = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $tags = [];

    /**
     * @var array<string, AutowireFactory>
     */
    private array $autowireFactories = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $contextBindings = [];

    /**
     * @var array<string, true>
     */
    private array $attemptedAutowires = [];

    /**
     * @var array<string, true>
     */
    private array $initializingLazy = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * @var list<string>
     */
    private array $resolutionPath = [];

    /**
     * @var array<string, mixed>
     */
    private array $scopedEntries = [];

    private bool $frozen = false;

    private ?object $scopeToken = null;

    private int $resolvingSingletons = 0;

    private readonly ResolutionExecutionContext $execution;

    /**
     * The optional policy is an internal composition hook.
     *
     * @internal
     */
    public function __construct(
        private readonly ?ResolutionAccessPolicyInterface $accessPolicy = null,
    ) {
        $this->execution = new ResolutionExecutionContext();
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function register(string $id, mixed $value): void
    {
        $this->assertAvailable($id);

        $this->entries['entry:' . $id] = $value;
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function factory(string $id, Closure $factory): void
    {
        $this->registerFactory($id, $factory, ServiceLifetime::Transient);
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->registerFactory($id, $factory, ServiceLifetime::Singleton);
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function scoped(string $id, Closure $factory): void
    {
        $this->registerFactory($id, $factory, ServiceLifetime::Scoped);
    }

    /**
     * @param Closure(ContainerInterface): mixed $operation
     */
    public function runInScope(Closure $operation): mixed
    {
        return $this->execution->run(
            $this->execution->requester(),
            function () use ($operation): mixed {
                if ($this->scopeToken !== null || $this->resolutionPath !== []) {
                    throw new ScopeStateException(
                        message: 'A scope cannot begin during another scope or service resolution.',
                        dependencyPath: $this->resolutionPath,
                    );
                }

                $this->scopedEntries = [];
                $this->scopeToken = new stdClass();

                try {
                    return $operation($this);
                } finally {
                    $this->scopeToken = null;
                    $this->scopedEntries = [];
                }
            },
        );
    }

    public function defer(string $id): DeferredService
    {
        return $this->execution->run(
            $this->execution->requester(),
            function () use ($id): DeferredService {
                $this->assertValidIdentifier($id);
                $this->assertAccess($id);

                if (!$this->has($id)) {
                    throw new EntryNotFoundException(
                        'No entry is registered for the deferred identifier.',
                    );
                }

                $requestedKey = 'entry:' . $id;
                $key = $this->aliases[$requestedKey] ?? $requestedKey;

                if (
                    ($this->definitions[$key]['lifetime'] ?? null)
                    === ServiceLifetime::Scoped
                ) {
                    $this->assertScopedResolutionAllowed();
                }

                $scopeToken = $this->scopeToken;
                $singletonRestricted = $this->resolvingSingletons > 0;
                $requester = $this->execution->requester();

                return new DeferredService(
                    fn (): mixed => $this->resolveDeferred(
                        $id,
                        $scopeToken,
                        $singletonRestricted,
                        $requester,
                    ),
                );
            },
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    public function autowire(
        string $id,
        string $class,
        bool $shared = false,
        array $arguments = [],
        array $taggedArguments = [],
    ): void {
        $this->registerAutowire(
            $id,
            $class,
            $shared ? ServiceLifetime::Singleton : ServiceLifetime::Transient,
            $arguments,
            $taggedArguments,
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    public function scopedAutowire(
        string $id,
        string $class,
        array $arguments = [],
        array $taggedArguments = [],
    ): void {
        $this->registerAutowire(
            $id,
            $class,
            ServiceLifetime::Scoped,
            $arguments,
            $taggedArguments,
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    public function lazySingleton(
        string $id,
        string $class,
        array $arguments = [],
        array $taggedArguments = [],
    ): void {
        $this->registerAutowire(
            $id,
            $class,
            ServiceLifetime::Singleton,
            $arguments,
            $taggedArguments,
            true,
        );
    }

    /**
     * Register a lazy singleton whose argument resolution is already prepared.
     *
     * The resolver runs during initialization, including each retry.
     * Contextual bindings must already be incorporated into the resolver.
     *
     * @internal
     *
     * @param Closure(ContainerInterface): array<array-key, mixed> $arguments
     */
    public function preparedLazySingleton(
        string $id,
        string $class,
        Closure $arguments,
    ): void {
        $this->assertAvailable($id);

        try {
            $lazyClass = new LazyClass($class);
        } catch (Throwable $previous) {
            throw new ResolutionException(
                message: 'The prepared lazy entry could not be registered.',
                previous: $previous,
            );
        }

        $this->registerLazyFactory($id, $lazyClass, $arguments);
    }

    public function bindContext(
        string $consumer,
        string $dependency,
        string $target,
    ): void {
        $this->assertMutable();
        $this->assertValidIdentifier($consumer);
        $this->assertValidIdentifier($dependency);
        $this->assertValidIdentifier($target);

        $requestedKey = 'entry:' . $consumer;
        $key = $this->aliases[$requestedKey] ?? $requestedKey;

        if (!isset($this->autowireFactories[$key])) {
            throw new ContextBindingException(
                'A contextual consumer must be an autowired registration.',
            );
        }

        if (isset($this->attemptedAutowires[$key])) {
            throw new ContextBindingException(
                'Contextual bindings are locked after the first resolution attempt.',
            );
        }

        if (!$this->autowireFactories[$key]->supportsDependency($dependency)) {
            throw new ContextBindingException(
                'The dependency does not match a named constructor dependency.',
            );
        }

        if (!$this->has($target)) {
            throw new EntryNotFoundException(
                'No entry is registered for the contextual target.',
            );
        }

        if (isset($this->contextBindings[$key][$dependency])) {
            throw new ContextBindingException(
                'A contextual binding already exists for this dependency.',
            );
        }

        $this->contextBindings[$key][$dependency] = $target;
    }

    public function alias(string $alias, string $target): void
    {
        $this->assertAvailable($alias);
        $this->assertValidIdentifier($target);

        if (!$this->has($target)) {
            throw new EntryNotFoundException(
                'No entry is registered for the alias target.',
            );
        }

        $targetKey = 'entry:' . $target;

        $this->aliases['entry:' . $alias] = $this->aliases[$targetKey]
            ?? $targetKey;
    }

    public function tag(string $tag, string ...$ids): void
    {
        $this->assertMutable();
        $this->assertValidTag($tag);

        if ($ids === []) {
            return;
        }

        $key = 'tag:' . $tag;
        $members = $this->tags[$key] ?? [];

        foreach ($ids as $id) {
            $this->assertValidIdentifier($id);

            if (!$this->has($id)) {
                throw new EntryNotFoundException(
                    'No entry is registered for a requested tag member.',
                );
            }

            $members['entry:' . $id] = $id;
        }

        $this->tags[$key] = $members;
    }

    /**
     * @return list<mixed>
     */
    public function tagged(string $tag): array
    {
        $this->assertValidTag($tag);

        $members = $this->tags['tag:' . $tag] ?? [];
        $values = [];

        foreach ($members as $id) {
            $values[] = $this->get($id);
        }

        return $values;
    }

    public function has(string $id): bool
    {
        $key = 'entry:' . $id;

        return array_key_exists($key, $this->entries)
            || array_key_exists($key, $this->definitions)
            || array_key_exists($key, $this->aliases);
    }

    public function get(string $id): mixed
    {
        return $this->execution->run(
            $this->execution->requester(),
            function () use ($id): mixed {
                $this->resolutionPath[] = $id;

                try {
                    $this->assertAccess($id);

                    return $this->execution->run(
                        $this->canonicalIdentifier($id),
                        fn (): mixed => $this->resolve($id),
                    );
                } finally {
                    array_pop($this->resolutionPath);
                }
            },
        );
    }

    private function canonicalIdentifier(string $id): string
    {
        $requestedKey = 'entry:' . $id;
        $key = $this->aliases[$requestedKey] ?? $requestedKey;

        return substr($key, 6);
    }

    private function assertAccess(string $id): void
    {
        if (
            $this->accessPolicy !== null
            && !$this->accessPolicy->allows(
                $this->execution->requester(),
                $this->canonicalIdentifier($id),
            )
        ) {
            throw new ResolutionException(
                message: 'Access to the requested service is denied.',
                dependencyPath: $this->resolutionPath,
            );
        }
    }

    private function resolveDeferred(
        string $id,
        ?object $scopeToken,
        bool $singletonRestricted,
        ?string $requester,
    ): mixed {
        return $this->execution->run(
            $requester,
            function () use ($id, $scopeToken, $singletonRestricted): mixed {
                if ($scopeToken !== null && $scopeToken !== $this->scopeToken) {
                    throw new ScopeStateException(
                        message: 'The deferred reference belongs to a scope that has ended.',
                        dependencyPath: [...$this->resolutionPath, $id],
                    );
                }

                if ($singletonRestricted) {
                    ++$this->resolvingSingletons;
                }

                try {
                    return $this->get($id);
                } finally {
                    if ($singletonRestricted) {
                        --$this->resolvingSingletons;
                    }
                }
            },
        );
    }

    private function resolve(string $id): mixed
    {
        $requestedKey = 'entry:' . $id;
        $key = $this->aliases[$requestedKey] ?? $requestedKey;

        if (isset($this->autowireFactories[$key])) {
            $this->attemptedAutowires[$key] = true;
        }

        if (isset($this->initializingLazy[$key])) {
            throw new ResolutionException(
                message: 'A circular lazy service dependency was detected.',
                dependencyPath: $this->resolutionPath,
            );
        }

        if (array_key_exists($key, $this->entries)) {
            return $this->entries[$key];
        }

        if (!array_key_exists($key, $this->definitions)) {
            throw new EntryNotFoundException(
                message: 'No entry is registered for the requested identifier.',
                dependencyPath: $this->resolutionPath,
            );
        }

        $definition = $this->definitions[$key];
        $lifetime = $definition['lifetime'];

        if ($lifetime === ServiceLifetime::Scoped) {
            $this->assertScopedResolutionAllowed();

            if (array_key_exists($key, $this->scopedEntries)) {
                return $this->scopedEntries[$key];
            }
        }

        if (isset($this->resolving[$key])) {
            throw new ResolutionException(
                message: 'A circular container dependency was detected.',
                dependencyPath: $this->resolutionPath,
            );
        }

        $this->resolving[$key] = true;

        if ($lifetime === ServiceLifetime::Singleton) {
            ++$this->resolvingSingletons;
        }

        try {
            $value = ($definition['factory'])($this);
        } catch (Throwable $previous) {
            throw $this->wrapResolutionFailure($previous);
        } finally {
            if ($lifetime === ServiceLifetime::Singleton) {
                --$this->resolvingSingletons;
            }

            unset($this->resolving[$key]);
        }

        if ($lifetime === ServiceLifetime::Singleton) {
            $this->entries[$key] = $value;
        } elseif ($lifetime === ServiceLifetime::Scoped) {
            $this->scopedEntries[$key] = $value;
        }

        return $value;
    }

    /**
     * @param Closure(ContainerInterface): array<array-key, mixed> $arguments
     */
    private function initializeLazySingleton(
        string $id,
        string $key,
        LazyClass $lazyClass,
        Closure $arguments,
        object $object,
    ): void {
        $this->execution->run(
            $id,
            function () use ($id, $key, $lazyClass, $arguments, $object): void {
                if (isset($this->initializingLazy[$key])) {
                    throw new ResolutionException(
                        message: 'A circular lazy service dependency was detected.',
                        dependencyPath: [...$this->resolutionPath, $id],
                    );
                }

                $this->resolutionPath[] = $id;
                $this->initializingLazy[$key] = true;
                ++$this->resolvingSingletons;

                try {
                    $lazyClass->initialize($object, $arguments($this));
                } catch (Throwable $previous) {
                    throw $this->wrapResolutionFailure($previous);
                } finally {
                    --$this->resolvingSingletons;
                    unset($this->initializingLazy[$key]);
                    array_pop($this->resolutionPath);
                }
            },
        );
    }

    private function wrapResolutionFailure(Throwable $previous): ResolutionException
    {
        $dependencyPath = $this->resolutionPath;

        if ($previous instanceof ContainerException) {
            $previousPath = $previous->dependencyPath();

            if ($previousPath !== []) {
                $dependencyPath = $previousPath;
            }
        }

        return new ResolutionException(
            message: 'The requested entry could not be resolved.',
            previous: $previous,
            dependencyPath: $dependencyPath,
        );
    }

    private function assertScopedResolutionAllowed(): void
    {
        if ($this->scopeToken === null) {
            throw new ScopeStateException(
                message: 'A scoped service requires an active scope.',
                dependencyPath: $this->resolutionPath,
            );
        }

        if ($this->resolvingSingletons > 0) {
            throw new LifetimeViolationException(
                message: 'Singleton construction cannot resolve a scoped service.',
                dependencyPath: $this->resolutionPath,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    private function registerAutowire(
        string $id,
        string $class,
        ServiceLifetime $lifetime,
        array $arguments,
        array $taggedArguments,
        bool $lazy = false,
    ): void {
        $this->assertAvailable($id);

        try {
            $factory = new AutowireFactory(
                $class,
                $arguments,
                $taggedArguments,
            );

            $lazyClass = $lazy ? new LazyClass($class) : null;
        } catch (Throwable $previous) {
            throw new ResolutionException(
                message: 'The autowired entry could not be registered.',
                previous: $previous,
            );
        }

        $key = 'entry:' . $id;

        if ($lazyClass !== null) {
            $this->registerLazyFactory(
                $id,
                $lazyClass,
                fn (ContainerInterface $resolver): array => $factory->resolveArguments(
                    $resolver,
                    $this->tagged(...),
                    $this->contextBindings[$key] ?? [],
                ),
            );
        } else {
            $this->registerFactory(
                $id,
                fn (ContainerInterface $resolver): object => $factory(
                    $resolver,
                    $this->tagged(...),
                    $this->contextBindings[$key] ?? [],
                ),
                $lifetime,
            );
        }

        $this->autowireFactories[$key] = $factory;
    }

    /**
     * @param Closure(ContainerInterface): array<array-key, mixed> $arguments
     */
    private function registerLazyFactory(
        string $id,
        LazyClass $lazyClass,
        Closure $arguments,
    ): void {
        $key = 'entry:' . $id;

        $this->registerFactory(
            $id,
            function (ContainerInterface $resolver) use (
                $id,
                $key,
                $lazyClass,
                $arguments,
            ): object {
                return $lazyClass->create(
                    function (object $object) use (
                        $id,
                        $key,
                        $lazyClass,
                        $arguments,
                    ): void {
                        $this->initializeLazySingleton(
                            $id,
                            $key,
                            $lazyClass,
                            $arguments,
                            $object,
                        );
                    },
                );
            },
            ServiceLifetime::Singleton,
        );
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    private function registerFactory(
        string $id,
        Closure $factory,
        ServiceLifetime $lifetime,
    ): void {
        $this->assertAvailable($id);

        $this->definitions['entry:' . $id] = [
            'factory' => $factory,
            'lifetime' => $lifetime,
        ];
    }

    private function assertAvailable(string $id): void
    {
        $this->assertMutable();
        $this->assertValidIdentifier($id);

        if ($this->has($id)) {
            throw new DuplicateEntryException(
                'An entry is already registered for the requested identifier.',
            );
        }
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new FrozenContainerException(
                'The container is frozen and cannot accept registration changes.',
            );
        }
    }

    private function assertValidIdentifier(string $id): void
    {
        if ($id === '') {
            throw new InvalidEntryIdentifierException(
                'Container entry identifiers must not be empty.',
            );
        }
    }

    private function assertValidTag(string $tag): void
    {
        if ($tag === '') {
            throw new InvalidTagException(
                'Container tag names must not be empty.',
            );
        }
    }
}
