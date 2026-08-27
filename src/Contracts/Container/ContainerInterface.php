<?php

declare(strict_types=1);

namespace Careminate\Contracts\Container;

use Careminate\Container\ResolutionDiagnostic;
use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    /**
     * @return iterable<mixed>
     */
    public function tagged(string $tag): iterable;

    public function createScope(string $name): ScopedContainerInterface;

    public function diagnose(string $id): ResolutionDiagnostic;
}