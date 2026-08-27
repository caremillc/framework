<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Exception\Container\CircularDependencyException;

final class ResolutionContext
{
    /**
     * @var list<string>
     */
    private array $stack = [];

    public function enter(string $id): void
    {
        $position = array_search($id, $this->stack, true);

        if ($position !== false) {
            $cycle = array_slice($this->stack, $position);
            $cycle[] = $id;

            throw CircularDependencyException::forPath($cycle);
        }

        $this->stack[] = $id;
    }

    public function leave(): void
    {
        array_pop($this->stack);
    }

    /**
     * @return list<string>
     */
    public function path(): array
    {
        return $this->stack;
    }
}
