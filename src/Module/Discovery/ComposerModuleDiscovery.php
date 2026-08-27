<?php

declare(strict_types=1);

namespace Careminate\Module\Discovery;

use Careminate\Contracts\Module\ModuleDiscoveryInterface;
use Careminate\Exception\Module\InvalidModuleException;
use JsonException;

final readonly class ComposerModuleDiscovery implements ModuleDiscoveryInterface
{
    public function __construct(private string $installedJsonPath)
    {
    }

    public static function fromVendorDirectory(string $vendorDirectory): self
    {
        return new self(
            rtrim($vendorDirectory, '\\/') . DIRECTORY_SEPARATOR
            . 'composer' . DIRECTORY_SEPARATOR . 'installed.json',
        );
    }

    public function discover(): iterable
    {
        if (!is_file($this->installedJsonPath)) {
            return [];
        }

        $contents = file_get_contents($this->installedJsonPath);

        if ($contents === false) {
            throw new InvalidModuleException(sprintf(
                'Unable to read Composer package metadata at "%s".',
                $this->installedJsonPath,
            ));
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidModuleException(sprintf(
                'Composer package metadata "%s" is invalid JSON: %s',
                $this->installedJsonPath,
                $exception->getMessage(),
            ));
        }

        if (!is_array($data)) {
            throw new InvalidModuleException(
                'Composer installed package metadata must decode to an array.',
            );
        }

        $packages = $data['packages'] ?? $data;

        if (!is_array($packages)) {
            throw new InvalidModuleException(
                'Composer installed package metadata has no package collection.',
            );
        }

        $modules = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $extra = $package['extra'] ?? null;

            if (!is_array($extra)) {
                continue;
            }

            $careminate = $extra['careminate'] ?? null;

            if (!is_array($careminate)) {
                continue;
            }

            $declared = [];

            if (isset($careminate['module'])) {
                $declared[] = $careminate['module'];
            }

            if (isset($careminate['modules'])) {
                if (!is_array($careminate['modules'])) {
                    throw new InvalidModuleException(
                        'Composer extra.careminate.modules must be an array.',
                    );
                }

                $declared = [...$declared, ...$careminate['modules']];
            }

            foreach ($declared as $module) {
                if (!is_string($module) || trim($module) === '') {
                    throw new InvalidModuleException(
                        'Composer-discovered module names must be non-empty class strings.',
                    );
                }

                $modules[$module] = $module;
            }
        }

        ksort($modules);

        return array_values($modules);
    }
}
