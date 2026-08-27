<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

use Careminate\Application\ApplicationState;

final class LifecycleException extends ApplicationException
{
    public static function invalidTransition(
        ApplicationState $from,
        ApplicationState $to,
    ): self {
        return new self(
            sprintf(
                'Application state cannot transition from "%s" to "%s".',
                $from->value,
                $to->value,
            ),
        );
    }

    public static function expected(
        ApplicationState $expected,
        ApplicationState $actual,
    ): self {
        return new self(
            sprintf(
                'Application state "%s" was required; current state is "%s".',
                $expected->value,
                $actual->value,
            ),
        );
    }

    public static function builderAlreadyUsed(): self
    {
        return new self(
            'An ApplicationBuilder instance can build only one application.',
        );
    }
}
