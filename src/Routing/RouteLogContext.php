<?php

declare(strict_types=1);

namespace Flint\Routing;

final class RouteLogContext
{
    private ?string $routeName = null;

    public function select(?string $routeName): void
    {
        $this->routeName = $routeName;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }
}
