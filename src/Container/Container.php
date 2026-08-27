<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Container\Attribute\Inject;
use Careminate\Container\Attribute\Lazy;
use Careminate\Container\Attribute\Tagged;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Contracts\Container\ContainerRegistryInterface;
use Careminate\Contracts\Container\ContextualBindingBuilderInterface;
use Careminate\Contracts\Container\FactoryInterface;
use Careminate\Contracts\Container\ScopedContainerInterface;
use Careminate\Exception\Container\ContainerCompilationException;
use Careminate\Exception\Container\ContainerException;
use Careminate\Exception\Container\FrozenContainerException;
use Careminate\Exception\Container\InvalidDefinitionException;
use Careminate\Exception\Container\NotFoundException;
use Careminate\Exception\Container\ScopeException;
use Careminate\Exception\Container\UnresolvableDependencyException;
use Careminate\Foundation\Version;
use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

final class Container implements ContainerInterface, ContainerRegistryInterface
{
    private const SNAPSHOT_FORMAT = 1;

    /**
     * @var array<string, Definition>
     */
    private array $definitions = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, list<string>>
     */
    private array $tags = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $contextualBindings = [];

    /**
     * @var array<string, mixed>
     */
    private array $singletons = [];

    /**
     * @var array<class-string, ReflectionClass<object>>
     */
    private array $reflectionCache = [];

    private bool $frozen = false;

    public function get(string $id): mixed
    {
        return $this->resolve(
            $id,
            $this,
            null,
            new ResolutionContext(),
        );
    }

    public function has(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        try {
            $resolvedId = $this->resolveAlias($id);
        } catch (ContainerException) {
            return false;
        }

        if ($this->isRootContainerIdentifier($resolvedId)) {
            return true;
        }

        if (array_key_exists($resolvedId, $this->definitions)) {
            return true;
        }

        if (!class_exists($resolvedId)) {
            return false;
        }

        try {
            return $this->reflection($resolvedId)->isInstantiable();
        } catch (Throwable) {
            return false;
        }
    }

    public function bind(
        string $id,
        string|callable|null $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
        bool $lazy = false,
    ): static {
        return $this->registerBinding(
            $id,
            $concrete,
            $lifetime,
            $lazy,
            false,
        );
    }

    public function replace(
        string $id,
        string|callable|null $concrete = null,
        Lifetime $lifetime = Lifetime::Transient,
        bool $lazy = false,
    ): static {
        return $this->registerBinding(
            $id,
            $concrete,
            $lifetime,
            $lazy,
            true,
        );
    }

    public function singleton(
        string $id,
        string|callable|null $concrete = null,
    ): static {
        return $this->bind(
            $id,
            $concrete,
            Lifetime::Singleton,
        );
    }

    public function scoped(
        string $id,
        string|callable|null $concrete = null,
    ): static {
        return $this->bind(
            $id,
            $concrete,
            Lifetime::Scoped,
        );
    }

    public function lazy(
        string $id,
        ?string $concrete = null,
        Lifetime $lifetime = Lifetime::Singleton,
    ): static {
        return $this->bind(
            $id,
            $concrete,
            $lifetime,
            true,
        );
    }

    public function factory(
        string $id,
        string|callable|FactoryInterface $factory,
        Lifetime $lifetime = Lifetime::Transient,
    ): static {
        $this->assertMutable();
        $this->validateIdentifier($id);
        $this->assertNotReserved($id);
        $this->assertAvailable($id, false);

        if ($factory instanceof FactoryInterface) {
            $definition = Definition::forFactoryObject(
                $id,
                $factory,
                $lifetime,
            );
        } elseif (is_string($factory)) {
            $definition = Definition::forFactoryService(
                $id,
                $factory,
                $lifetime,
            );
        } else {
            $definition = Definition::forCallableFactory(
                $id,
                $factory,
                $lifetime,
            );
        }

        $this->definitions[$id] = $definition;

        return $this;
    }

    public function instance(string $id, mixed $instance): static
    {
        $this->assertMutable();
        $this->validateIdentifier($id);
        $this->assertNotReserved($id);
        $this->assertAvailable($id, false);

        $this->definitions[$id] = Definition::forValue($id, $instance);
        $this->singletons[$id] = $instance;

        return $this;
    }

    public function alias(string $alias, string $id): static
    {
        $this->assertMutable();
        $this->validateIdentifier($alias);
        $this->validateIdentifier($id);
        $this->assertNotReserved($alias);
        $this->assertAvailable($alias, false);

        if ($alias === $id) {
            throw InvalidDefinitionException::invalidAlias($alias, $id);
        }

        $this->aliases[$alias] = $id;

        try {
            $this->resolveAlias($alias);
        } catch (Throwable $exception) {
            unset($this->aliases[$alias]);

            throw $exception;
        }

        return $this;
    }

    public function tag(string|array $ids, string $tag): static
    {
        $this->assertMutable();
        $this->validateIdentifier($tag);

        $ids = is_string($ids) ? [$ids] : $ids;

        foreach ($ids as $id) {
            $this->validateIdentifier($id);

            if (!in_array($id, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $id;
            }
        }

        return $this;
    }

    public function tagged(string $tag): iterable
    {
        return $this->resolveTagged($tag, $this, null);
    }

    public function when(string $consumer): ContextualBindingBuilderInterface
    {
        $this->assertMutable();
        $this->validateIdentifier($consumer);

        return new ContextualBindingBuilder($this, $consumer);
    }

    public function addContextualBinding(
        string $consumer,
        string $dependency,
        mixed $implementation,
    ): void {
        $this->assertMutable();
        $this->validateIdentifier($consumer);
        $this->validateIdentifier($dependency);

        $this->contextualBindings[$consumer][$dependency] = $implementation;
    }

    public function createScope(string $name): ScopedContainerInterface
    {
        $this->validateIdentifier($name);

        return new ScopedContainer($this, $name);
    }

    public function diagnose(string $id): ResolutionDiagnostic
    {
        $registered = isset($this->definitions[$id])
            || isset($this->aliases[$id]);

        try {
            $resolvedId = $this->resolveAlias($id);
        } catch (ContainerException $exception) {
            return new ResolutionDiagnostic(
                $id,
                $id,
                $registered,
                false,
                null,
                null,
                false,
                [],
                [],
                $exception->getMessage(),
            );
        }

        $definition = $this->definitions[$resolvedId] ?? null;
        $resolvable = false;
        $error = null;

        if ($this->isRootContainerIdentifier($resolvedId)) {
            $resolvable = true;
        } elseif ($definition !== null) {
            if ($definition->kind() === DefinitionKind::ClassName) {
                $target = $definition->resolver();

                if (is_string($target) && class_exists($target)) {
                    $resolvable = $this->reflection($target)->isInstantiable();
                } else {
                    $error = sprintf('Class "%s" does not exist.', (string) $target);
                }
            } else {
                $resolvable = true;
            }
        } elseif (class_exists($resolvedId)) {
            $resolvable = $this->reflection($resolvedId)->isInstantiable();
        } else {
            $error = sprintf('No definition or autowireable class exists for "%s".', $resolvedId);
        }

        $aliases = [];

        foreach ($this->aliases as $alias => $target) {
            if ($target === $resolvedId || $alias === $id) {
                $aliases[] = $alias;
            }
        }

        $tags = [];

        foreach ($this->tags as $tag => $taggedIds) {
            if (
                in_array($id, $taggedIds, true)
                || in_array($resolvedId, $taggedIds, true)
            ) {
                $tags[] = $tag;
            }
        }

        return new ResolutionDiagnostic(
            $id,
            $resolvedId,
            $registered,
            $resolvable,
            $definition?->targetDescription() ?? (
                class_exists($resolvedId) ? $resolvedId : null
            ),
            $definition?->lifetime() ?? (
                class_exists($resolvedId) ? Lifetime::Transient : null
            ),
            $definition?->lazy() ?? false,
            $aliases,
            $tags,
            $error,
        );
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @internal
     */
    public function resolveForScope(
        string $id,
        ScopedContainer $scope,
    ): mixed {
        $scope->assertOpen();

        return $this->resolve(
            $id,
            $scope,
            $scope,
            new ResolutionContext(),
        );
    }

    /**
     * @internal
     *
     * @return list<mixed>
     */
    public function taggedForScope(
        string $tag,
        ScopedContainer $scope,
    ): array {
        $scope->assertOpen();

        return $this->resolveTagged($tag, $scope, $scope);
    }

    /**
     * @return array{
     *     format: int,
     *     framework_version: string,
     *     definitions: array<string, array<string, mixed>>,
     *     aliases: array<string, string>,
     *     tags: array<string, list<string>>,
     *     contextual: array<string, array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $definitions = [];

        foreach ($this->definitions as $id => $definition) {
            $definitions[$id] = $definition->toSnapshot();
            $this->assertExportable(
                $definitions[$id]['resolver'],
                sprintf('definitions.%s.resolver', $id),
            );
        }

        $this->assertExportable($this->contextualBindings, 'contextual');

        return [
            'format' => self::SNAPSHOT_FORMAT,
            'framework_version' => Version::current(),
            'definitions' => $definitions,
            'aliases' => $this->aliases,
            'tags' => $this->tags,
            'contextual' => $this->contextualBindings,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        if (($snapshot['format'] ?? null) !== self::SNAPSHOT_FORMAT) {
            throw ContainerCompilationException::invalidSnapshot(
                'Unsupported format version.',
            );
        }

        if (($snapshot['framework_version'] ?? null) !== Version::current()) {
            throw ContainerCompilationException::invalidSnapshot(
                'The cache was generated for a different framework version.',
            );
        }

        $definitions = $snapshot['definitions'] ?? null;
        $aliases = $snapshot['aliases'] ?? null;
        $tags = $snapshot['tags'] ?? null;
        $contextual = $snapshot['contextual'] ?? null;

        if (
            !is_array($definitions)
            || !is_array($aliases)
            || !is_array($tags)
            || !is_array($contextual)
        ) {
            throw ContainerCompilationException::invalidSnapshot(
                'Required container sections are missing.',
            );
        }

        $container = new self();

        foreach ($definitions as $id => $definitionData) {
            if (!is_string($id) || !is_array($definitionData)) {
                throw ContainerCompilationException::invalidSnapshot(
                    'A definition entry has an invalid identifier or payload.',
                );
            }

            $definition = Definition::fromSnapshot($definitionData);

            if ($definition->id() !== $id) {
                throw ContainerCompilationException::invalidSnapshot(
                    sprintf('Definition key "%s" does not match its identifier.', $id),
                );
            }

            $container->definitions[$id] = $definition;

            if ($definition->kind() === DefinitionKind::Value) {
                $container->singletons[$id] = $definition->resolver();
            }
        }

        foreach ($aliases as $alias => $target) {
            if (!is_string($alias) || !is_string($target)) {
                throw ContainerCompilationException::invalidSnapshot(
                    'An alias entry is invalid.',
                );
            }

            $container->aliases[$alias] = $target;
        }

        foreach ($tags as $tag => $ids) {
            if (!is_string($tag) || !is_array($ids)) {
                throw ContainerCompilationException::invalidSnapshot(
                    'A tag entry is invalid.',
                );
            }

            foreach ($ids as $id) {
                if (!is_string($id)) {
                    throw ContainerCompilationException::invalidSnapshot(
                        sprintf('Tag "%s" contains a non-string identifier.', $tag),
                    );
                }

                $container->tags[$tag][] = $id;
            }
        }

        foreach ($contextual as $consumer => $bindings) {
            if (!is_string($consumer) || !is_array($bindings)) {
                throw ContainerCompilationException::invalidSnapshot(
                    'A contextual-binding entry is invalid.',
                );
            }

            foreach ($bindings as $dependency => $implementation) {
                if (!is_string($dependency)) {
                    throw ContainerCompilationException::invalidSnapshot(
                        sprintf(
                            'Consumer "%s" contains an invalid dependency key.',
                            $consumer,
                        ),
                    );
                }

                $container->contextualBindings[$consumer][$dependency] = $implementation;
            }
        }

        foreach (array_keys($container->aliases) as $alias) {
            $container->resolveAlias($alias);
        }

        $container->frozen = true;

        return $container;
    }

    private function registerBinding(
        string $id,
        string|callable|null $concrete,
        Lifetime $lifetime,
        bool $lazy,
        bool $replace,
    ): static {
        $this->assertMutable();
        $this->validateIdentifier($id);
        $this->assertNotReserved($id);
        $this->assertAvailable($id, $replace);

        $concrete ??= $id;

        if (is_string($concrete)) {
            $definition = Definition::forClass(
                $id,
                $concrete,
                $lifetime,
                $lazy,
            );
        } elseif (is_callable($concrete)) {
            if ($lazy) {
                throw new InvalidDefinitionException(
                    sprintf(
                        'Lazy entry "%s" must use a concrete class name.',
                        $id,
                    ),
                );
            }

            $definition = Definition::forCallableFactory(
                $id,
                $concrete,
                $lifetime,
            );
        } else {
            throw InvalidDefinitionException::invalidConcrete($id);
        }

        $this->definitions[$id] = $definition;

        return $this;
    }

    private function assertAvailable(string $id, bool $replace): void
    {
        $exists = array_key_exists($id, $this->definitions)
            || array_key_exists($id, $this->aliases);

        if ($exists && !$replace) {
            throw InvalidDefinitionException::duplicate($id);
        }

        if ($replace) {
            unset(
                $this->definitions[$id],
                $this->aliases[$id],
                $this->singletons[$id],
            );
        }
    }

    private function resolve(
        string $id,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): mixed {
        if ($id === '') {
            throw NotFoundException::forIdentifier($id, $context->path());
        }

        $resolvedId = $this->resolveAlias($id);

        $special = $this->resolveSpecialIdentifier($resolvedId, $resolver);

        if ($special['matched']) {
            return $special['value'];
        }

        $definition = $this->definitions[$resolvedId] ?? null;

        if ($definition === null) {
            if (!class_exists($resolvedId)) {
                throw NotFoundException::forIdentifier(
                    $resolvedId,
                    $context->path(),
                );
            }

            $definition = Definition::forClass(
                $resolvedId,
                $resolvedId,
                Lifetime::Transient,
                false,
            );
        }

        if (
            $definition->lifetime() === Lifetime::Singleton
            && array_key_exists($resolvedId, $this->singletons)
        ) {
            return $this->singletons[$resolvedId];
        }

        if ($definition->lifetime() === Lifetime::Scoped) {
            if ($scope === null) {
                throw ScopeException::required($resolvedId);
            }

            if ($scope->hasInstance($resolvedId)) {
                return $scope->instance($resolvedId);
            }
        }

        $context->enter($resolvedId);

        try {
            $value = $this->createFromDefinition(
                $definition,
                $resolver,
                $scope,
                $context,
            );
        } catch (ContainerExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnresolvableDependencyException::whileResolving(
                $resolvedId,
                $context->path(),
                $exception,
            );
        } finally {
            $context->leave();
        }

        if ($definition->lifetime() === Lifetime::Singleton) {
            $this->singletons[$resolvedId] = $value;
        }

        if ($definition->lifetime() === Lifetime::Scoped) {
            $scope?->remember($resolvedId, $value);
        }

        return $value;
    }

    private function createFromDefinition(
        Definition $definition,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): mixed {
        return match ($definition->kind()) {
            DefinitionKind::ClassName => $this->instantiateDefinition(
                $definition,
                $resolver,
                $scope,
                $context,
            ),
            DefinitionKind::CallableFactory => $this->invokeCallableFactory(
                $definition,
                $resolver,
            ),
            DefinitionKind::FactoryService => $this->invokeFactoryService(
                $definition,
                $resolver,
                $scope,
                $context,
            ),
            DefinitionKind::FactoryObject => $this->invokeFactoryObject(
                $definition,
                $resolver,
            ),
            DefinitionKind::Value => $definition->resolver(),
        };
    }

    private function invokeCallableFactory(
        Definition $definition,
        ContainerInterface $resolver,
    ): mixed {
        $factory = $definition->resolver();

        if (!$factory instanceof Closure) {
            throw InvalidDefinitionException::invalidConcrete($definition->id());
        }

        return $factory($resolver);
    }

    private function invokeFactoryService(
        Definition $definition,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): mixed {
        $factoryId = $definition->resolver();

        if (!is_string($factoryId)) {
            throw InvalidDefinitionException::invalidConcrete($definition->id());
        }

        $factory = $this->resolve(
            $factoryId,
            $resolver,
            $scope,
            $context,
        );

        if (!$factory instanceof FactoryInterface) {
            throw new InvalidDefinitionException(
                sprintf(
                    'Factory service "%s" must implement "%s".',
                    $factoryId,
                    FactoryInterface::class,
                ),
            );
        }

        return $factory->create($resolver);
    }

    private function invokeFactoryObject(
        Definition $definition,
        ContainerInterface $resolver,
    ): mixed {
        $factory = $definition->resolver();

        if (!$factory instanceof FactoryInterface) {
            throw InvalidDefinitionException::invalidConcrete($definition->id());
        }

        return $factory->create($resolver);
    }

    private function instantiateDefinition(
        Definition $definition,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
        bool $skipLazy = false,
    ): object {
        $className = $definition->resolver();

        if (!is_string($className) || !class_exists($className)) {
            throw NotFoundException::forIdentifier(
                is_string($className) ? $className : $definition->id(),
                $context->path(),
            );
        }

        $reflection = $this->reflection($className);

        if (!$reflection->isInstantiable()) {
            throw new InvalidDefinitionException(
                sprintf('Class "%s" is not instantiable.', $className),
                $context->path(),
            );
        }

        $usesLazyAttribute = $reflection->getAttributes(Lazy::class) !== [];

        if (!$skipLazy && ($definition->lazy() || $usesLazyAttribute)) {
            return $this->createLazyProxy(
                $definition,
                $reflection,
                $resolver,
                $scope,
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                foreach (
                    $this->resolveVariadicParameter(
                        $parameter,
                        $className,
                        $resolver,
                        $scope,
                        $context,
                    ) as $value
                ) {
                    $arguments[] = $value;
                }

                continue;
            }

            $arguments[] = $this->resolveParameter(
                $parameter,
                $className,
                $resolver,
                $scope,
                $context,
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function createLazyProxy(
        Definition $definition,
        ReflectionClass $reflection,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
    ): object {
        $proxy = $reflection->newLazyProxy(
            function (object $proxy) use (
                $definition,
                $resolver,
                $scope,
            ): object {
                unset($proxy);

                $scope?->assertOpen();

                return $this->instantiateDefinition(
                    $definition,
                    $resolver,
                    $scope,
                    new ResolutionContext(),
                    true,
                );
            },
        );

        if (!$reflection->isUninitializedLazyObject($proxy)) {
            return $this->instantiateDefinition(
                $definition,
                $resolver,
                $scope,
                new ResolutionContext(),
                true,
            );
        }

        return $proxy;
    }

    private function resolveParameter(
        ReflectionParameter $parameter,
        string $consumer,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): mixed {
        $injectAttributes = $parameter->getAttributes(Inject::class);

        if ($injectAttributes !== []) {
            $inject = $injectAttributes[0]->newInstance();

            return $this->resolve(
                $inject->id,
                $resolver,
                $scope,
                $context,
            );
        }

        $taggedAttributes = $parameter->getAttributes(Tagged::class);

        if ($taggedAttributes !== []) {
            $tagged = $taggedAttributes[0]->newInstance();

            return $this->resolveTagged(
                $tagged->tag,
                $resolver,
                $scope,
            );
        }

        [$hasPrimitiveBinding, $primitiveBinding] = $this->contextualBinding(
            $consumer,
            '$' . $parameter->getName(),
        );

        if ($hasPrimitiveBinding) {
            return $this->resolveContextualValue(
                $primitiveBinding,
                true,
                $resolver,
                $scope,
                $context,
            );
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $this->normalizeTypeName($type, $parameter);

            [$hasContextualBinding, $contextualBinding] =
                $this->contextualBinding($consumer, $dependency);

            if ($hasContextualBinding) {
                return $this->resolveContextualValue(
                    $contextualBinding,
                    false,
                    $resolver,
                    $scope,
                    $context,
                );
            }

            return $this->resolve(
                $dependency,
                $resolver,
                $scope,
                $context,
            );
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->resolveUnionParameter(
                $parameter,
                $type,
                $consumer,
                $resolver,
                $scope,
                $context,
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw UnresolvableDependencyException::forParameter(
                $consumer,
                $parameter->getName(),
                $context->path(),
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type?->allowsNull() === true) {
            return null;
        }

        throw UnresolvableDependencyException::forParameter(
            $consumer,
            $parameter->getName(),
            $context->path(),
        );
    }

    /**
     * @return list<mixed>
     */
    private function resolveVariadicParameter(
        ReflectionParameter $parameter,
        string $consumer,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): array {
        $taggedAttributes = $parameter->getAttributes(Tagged::class);

        if ($taggedAttributes !== []) {
            $tagged = $taggedAttributes[0]->newInstance();

            return $this->resolveTagged(
                $tagged->tag,
                $resolver,
                $scope,
            );
        }

        [$found, $value] = $this->contextualBinding(
            $consumer,
            '$' . $parameter->getName(),
        );

        if (!$found) {
            return [];
        }

        $resolved = $this->resolveContextualValue(
            $value,
            true,
            $resolver,
            $scope,
            $context,
        );

        if (!is_iterable($resolved)) {
            throw UnresolvableDependencyException::forParameter(
                $consumer,
                $parameter->getName(),
                $context->path(),
            );
        }

        return [...$resolved];
    }

    private function resolveUnionParameter(
        ReflectionParameter $parameter,
        ReflectionUnionType $type,
        string $consumer,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): mixed {
        $candidates = [];

        foreach ($type->getTypes() as $member) {
            if (!$member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $dependency = $this->normalizeTypeName($member, $parameter);

            if ($resolver->has($dependency)) {
                $candidates[] = $dependency;
            }
        }

        if (count($candidates) === 1) {
            return $this->resolve(
                $candidates[0],
                $resolver,
                $scope,
                $context,
            );
        }

        if (count($candidates) > 1) {
            throw UnresolvableDependencyException::ambiguousUnion(
                $consumer,
                $parameter->getName(),
                $context->path(),
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type->allowsNull()) {
            return null;
        }

        throw UnresolvableDependencyException::forParameter(
            $consumer,
            $parameter->getName(),
            $context->path(),
        );
    }

    private function resolveContextualValue(
        mixed $implementation,
        bool $primitive,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
        ResolutionContext $context,
    ): mixed {
        if ($implementation instanceof FactoryInterface) {
            return $implementation->create($resolver);
        }

        if ($implementation instanceof Closure) {
            return $implementation($resolver);
        }

        if ($primitive) {
            return $implementation;
        }

        if (is_string($implementation)) {
            return $this->resolve(
                $implementation,
                $resolver,
                $scope,
                $context,
            );
        }

        return $implementation;
    }

    /**
     * @return list<mixed>
     */
    private function resolveTagged(
        string $tag,
        ContainerInterface $resolver,
        ?ScopedContainer $scope,
    ): array {
        $this->validateIdentifier($tag);
        $resolved = [];

        foreach ($this->tags[$tag] ?? [] as $id) {
            $resolved[] = $this->resolve(
                $id,
                $resolver,
                $scope,
                new ResolutionContext(),
            );
        }

        return $resolved;
    }

    /**
     * @return array{bool, mixed}
     */
    private function contextualBinding(
        string $consumer,
        string $dependency,
    ): array {
        $consumers = [$consumer];

        if (class_exists($consumer)) {
            $parents = class_parents($consumer);
            $interfaces = class_implements($consumer);

            if ($parents !== false) {
                $consumers = [...$consumers, ...array_values($parents)];
            }

            if ($interfaces !== false) {
                $consumers = [...$consumers, ...array_values($interfaces)];
            }
        }

        foreach ($consumers as $candidate) {
            if (
                isset($this->contextualBindings[$candidate])
                && array_key_exists(
                    $dependency,
                    $this->contextualBindings[$candidate],
                )
            ) {
                return [
                    true,
                    $this->contextualBindings[$candidate][$dependency],
                ];
            }
        }

        return [false, null];
    }

    private function normalizeTypeName(
        ReflectionNamedType $type,
        ReflectionParameter $parameter,
    ): string {
        $name = $type->getName();

        if ($name === 'self' || $name === 'static') {
            return $parameter->getDeclaringClass()?->getName() ?? $name;
        }

        if ($name === 'parent') {
            $parent = $parameter->getDeclaringClass()?->getParentClass();

            if ($parent === false || $parent === null) {
                return $name;
            }

            return $parent->getName();
        }

        return $name;
    }

    private function resolveAlias(string $id): string
    {
        $seen = [];
        $current = $id;

        while (isset($this->aliases[$current])) {
            if (in_array($current, $seen, true)) {
                $seen[] = $current;

                throw \Careminate\Exception\Container\CircularDependencyException::forPath(
                    $seen,
                );
            }

            $seen[] = $current;
            $current = $this->aliases[$current];
        }

        return $current;
    }

    /**
     * @return array{matched: bool, value: mixed}
     */
    private function resolveSpecialIdentifier(
        string $id,
        ContainerInterface $resolver,
    ): array {
        if (
            $id === PsrContainerInterface::class
            || $id === ContainerInterface::class
            || $id === self::class
        ) {
            return [
                'matched' => true,
                'value' => $resolver,
            ];
        }

        if (
            $resolver instanceof ScopedContainer
            && (
                $id === ScopedContainerInterface::class
                || $id === ScopedContainer::class
            )
        ) {
            return [
                'matched' => true,
                'value' => $resolver,
            ];
        }

        return [
            'matched' => false,
            'value' => null,
        ];
    }

    private function isRootContainerIdentifier(string $id): bool
    {
        return $id === PsrContainerInterface::class
            || $id === ContainerInterface::class
            || $id === self::class;
    }

    /**
     * @return ReflectionClass<object>
     *
     * @throws ReflectionException
     */
    private function reflection(string $className): ReflectionClass
    {
        if (!isset($this->reflectionCache[$className])) {
            /** @var class-string $className */
            $this->reflectionCache[$className] = new ReflectionClass($className);
        }

        return $this->reflectionCache[$className];
    }

    private function validateIdentifier(string $id): void
    {
        if ($id === '' || trim($id) !== $id) {
            throw InvalidDefinitionException::emptyIdentifier();
        }
    }

    private function assertNotReserved(string $id): void
    {
        if (
            $this->isRootContainerIdentifier($id)
            || $id === ScopedContainerInterface::class
            || $id === ScopedContainer::class
        ) {
            throw InvalidDefinitionException::reserved($id);
        }
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw FrozenContainerException::compiledContainer();
        }
    }

    private function assertExportable(
        mixed $value,
        string $location,
    ): void {
        if (
            $value === null
            || is_bool($value)
            || is_int($value)
            || is_float($value)
            || is_string($value)
        ) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertExportable(
                    $item,
                    $location . '.' . (string) $key,
                );
            }

            return;
        }

        throw ContainerCompilationException::nonExportableValue($location);
    }
}
