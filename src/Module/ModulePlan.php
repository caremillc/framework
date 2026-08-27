<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Exception\Module\ModuleCacheException;

final readonly class ModulePlan
{
    /**
     * @param list<class-string<ModuleInterface>> $orderedModules
     * @param list<string> $disabledModules
     */
    public function __construct(
        public array $orderedModules,
        public array $disabledModules,
        public string $fingerprint,
    ) {
        if (count($orderedModules) !== count(array_unique($orderedModules))) {
            throw new ModuleCacheException(
                'The module plan contains duplicate module classes.',
            );
        }
    }

    public function assertCompatible(ModuleRegistry $registry): void
    {
        if (!hash_equals($registry->fingerprint(), $this->fingerprint)) {
            throw new ModuleCacheException(
                'The cached module plan is stale. Rebuild the module cache.',
            );
        }

        $expected = array_map(
            static fn (RegisteredModule $module): string => $module->class,
            $registry->active(),
        );

        $actual = $this->orderedModules;

        sort($expected);
        sort($actual);

        if ($expected !== $actual) {
            throw new ModuleCacheException(
                'The cached module plan does not contain exactly the enabled modules.',
            );
        }
    }

    /**
     * @return array{
     *     ordered_modules: list<class-string<ModuleInterface>>,
     *     disabled_modules: list<string>,
     *     fingerprint: string
     * }
     */
    public function toArray(): array
    {
        return [
            'ordered_modules' => $this->orderedModules,
            'disabled_modules' => $this->disabledModules,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $ordered = $data['ordered_modules'] ?? null;
        $disabled = $data['disabled_modules'] ?? null;
        $fingerprint = $data['fingerprint'] ?? null;

        if (
            !is_array($ordered)
            || !is_array($disabled)
            || !is_string($fingerprint)
            || $fingerprint === ''
        ) {
            throw new ModuleCacheException(
                'The cached module plan has an invalid structure.',
            );
        }

        foreach ([...$ordered, ...$disabled] as $value) {
            if (!is_string($value) || $value === '') {
                throw new ModuleCacheException(
                    'Cached module plan entries must be non-empty strings.',
                );
            }
        }

        /** @var list<class-string<ModuleInterface>> $ordered */
        /** @var list<string> $disabled */

        return new self($ordered, $disabled, $fingerprint);
    }
}
