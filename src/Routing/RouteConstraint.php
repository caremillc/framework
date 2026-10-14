<?php

declare(strict_types=1);

namespace Flint\Routing;

enum RouteConstraint
{
    case Digits;
    case Slug;
    case Uuid;

    public function matches(string $value): bool
    {
        $pattern = match ($this) {
            self::Digits => '/\A[0-9]+\z/',
            self::Slug => '/\A[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/',
            self::Uuid =>
                '/\A[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}'
                . '-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\z/',
        };

        return preg_match($pattern, $value) === 1;
    }
}
