<?php

declare(strict_types=1);

namespace Flint\Container;

use Closure;
use Flint\Container\Exception\ContainerException;
use Flint\Container\Exception\EntryNotFoundException;
use Flint\Container\Exception\ResolutionException;
use Psr\Container\ContainerInterface;
use Throwable;

final class Container implements ContainerInterface
{
    /**
     * @var array<string, Closure(ContainerInterface): mixed>
     */
    private array $factories = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @var list<string>
     */
    private array $resolving = [];

    /**
     * @param callable(ContainerInterface): mixed $factory
     */
    public function bind(string $id, callable $factory): void
    {
        $this->assertCanRegister($id);

        $this->factories[$id] = Closure::fromCallable($factory);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->assertCanRegister($id);

        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->factories);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!array_key_exists($id, $this->factories)) {
            throw new EntryNotFoundException(
                sprintf(
                    'Container entry "%s" is not registered. Register it before resolution.',
                    $id,
                ),
            );
        }

        if (in_array($id, $this->resolving, true)) {
            $path = [...$this->resolving, $id];

            throw new ResolutionException(
                'Circular dependency detected: ' . implode(' -> ', $path),
            );
        }

        $this->resolving[] = $id;

        try {
            return ($this->factories[$id])($this);
        } catch (ResolutionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ResolutionException(
                sprintf(
                    'Could not resolve container entry "%s". Resolution path: %s. Inspect the previous exception.',
                    $id,
                    implode(' -> ', $this->resolving),
                ),
                0,
                $exception,
            );
        } finally {
            array_pop($this->resolving);
        }
    }

    private function assertCanRegister(string $id): void
    {
        if ($id === '') {
            throw new ContainerException(
                'Container entry identifiers must not be empty.',
            );
        }

        if ($this->resolving !== []) {
            throw new ContainerException(
                'Container registrations cannot change during resolution.',
            );
        }

        if ($this->has($id)) {
            throw new ContainerException(
                sprintf(
                    'Container entry "%s" is already registered.',
                    $id,
                ),
            );
        }
    }
}
