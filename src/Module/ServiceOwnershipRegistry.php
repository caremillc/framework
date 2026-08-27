<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Exception\Module\ModuleBoundaryViolationException;

final class ServiceOwnershipRegistry
{
    /** @var array<string, string> */
    private array $services = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, array<string, string>> */
    private array $tags = [];

    /** @var array<string, string> */
    private array $contextual = [];

    public function claimService(string $service, string $module): void
    {
        $owner = $this->services[$service] ?? null;

        if ($owner !== null && $owner !== $module) {
            throw new ModuleBoundaryViolationException(sprintf(
                'Module "%s" cannot register service "%s"; it is owned by module "%s".',
                $module,
                $service,
                $owner,
            ));
        }

        $this->services[$service] = $module;
    }

    public function assertReplaceable(string $service, string $module): void
    {
        $owner = $this->services[$service] ?? null;

        if ($owner !== $module) {
            throw new ModuleBoundaryViolationException(sprintf(
                'Module "%s" cannot replace service "%s"; its recorded owner is "%s".',
                $module,
                $service,
                $owner ?? 'the application or framework',
            ));
        }
    }

    public function claimAlias(
        string $service,
        string $alias,
        string $module,
    ): void {
        $this->assertOwnedService($service, $module);

        $owner = $this->aliases[$alias] ?? null;

        if ($owner !== null && $owner !== $module) {
            throw new ModuleBoundaryViolationException(sprintf(
                'Module "%s" cannot claim alias "%s"; it is owned by module "%s".',
                $module,
                $alias,
                $owner,
            ));
        }

        $this->aliases[$alias] = $module;
    }

    public function claimTag(
        string $tag,
        string $service,
        string $module,
    ): void {
        $this->assertOwnedService($service, $module);

        $this->tags[$tag][$service] = $module;
    }

    public function claimContextual(
        string $consumer,
        string $dependency,
        string $module,
    ): void {
        $key = $consumer . "\0" . $dependency;
        $owner = $this->contextual[$key] ?? null;

        if ($owner !== null && $owner !== $module) {
            throw new ModuleBoundaryViolationException(sprintf(
                'Module "%s" cannot replace contextual binding "%s -> %s"; '
                . 'it is owned by module "%s".',
                $module,
                $consumer,
                $dependency,
                $owner,
            ));
        }

        $this->contextual[$key] = $module;
    }

    public function ownerOf(string $service): ?string
    {
        return $this->services[$service] ?? null;
    }

    /**
     * @return array{
     *     services: array<string, string>,
     *     aliases: array<string, string>,
     *     tags: array<string, array<string, string>>,
     *     contextual: array<string, string>
     * }
     */
    public function snapshot(): array
    {
        $services = $this->services;
        $aliases = $this->aliases;
        $tags = $this->tags;
        $contextual = $this->contextual;

        ksort($services);
        ksort($aliases);
        ksort($tags);
        ksort($contextual);

        return compact('services', 'aliases', 'tags', 'contextual');
    }

    private function assertOwnedService(string $service, string $module): void
    {
        $owner = $this->services[$service] ?? null;

        if ($owner !== $module) {
            throw new ModuleBoundaryViolationException(sprintf(
                'Module "%s" cannot modify service "%s"; its recorded owner is "%s".',
                $module,
                $service,
                $owner ?? 'the application or framework',
            ));
        }
    }
}

