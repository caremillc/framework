<?php

declare(strict_types=1);

namespace Careminate\Application\Bootstrap;

use Careminate\Contracts\Application\BootstrapperInterface;
use Careminate\Exception\Application\BootstrapException;
use Careminate\Exception\Application\ApplicationException;
use Throwable;

final class BootstrapSequence
{
    /**
     * @var list<array{
     *     bootstrapper: BootstrapperInterface,
     *     priority: int,
     *     order: int
     * }>
     */
    private array $entries = [];

    /**
     * @var array<class-string, true>
     */
    private array $registered = [];

    private int $nextOrder = 0;

    public function add(
        BootstrapperInterface $bootstrapper,
        int $priority = 0,
    ): void {
        $class = $bootstrapper::class;

        if (isset($this->registered[$class])) {
            throw new ApplicationException(
                sprintf(
                    'Bootstrapper "%s" is already registered.',
                    $class,
                ),
            );
        }

        $this->registered[$class] = true;

        $this->entries[] = [
            'bootstrapper' => $bootstrapper,
            'priority' => $priority,
            'order' => $this->nextOrder++,
        ];
    }

    public function execute(BootstrapContext $context): void
    {
        $entries = $this->entries;

        usort(
            $entries,
            static fn (array $left, array $right): int =>
                [$left['priority'], $left['order']]
                <=> [$right['priority'], $right['order']],
        );

        foreach ($entries as $entry) {
            try {
                $entry['bootstrapper']->bootstrap($context);
            } catch (Throwable $exception) {
                throw BootstrapException::forBootstrapper(
                    $entry['bootstrapper']::class,
                    $exception,
                );
            }
        }
    }

    /**
     * @return list<class-string>
     */
    public function bootstrappers(): array
    {
        return array_map(
            static fn (array $entry): string =>
                $entry['bootstrapper']::class,
            $this->entries,
        );
    }
}
