<?php

declare(strict_types=1);

namespace Flint\Routing;

final class RouteLogContext
{
    private bool $selected = false;

    private ?string $routeName = null;

    public function select(?string $routeName): void
    {
        if ($this->selected) {
            return;
        }

        $this->routeName = $routeName;
        $this->selected = true;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }
}
