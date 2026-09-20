<?php

declare(strict_types=1);

namespace Careminate\Module\Exception;

use Careminate\Exception\FrameworkException;
use Careminate\Module\ModuleIdentifier;
use Throwable;

/**
 * Preserves a provider or contribution-merging failure and its owner.
 *
 * @api
 */
final class ProviderRegistrationException extends FrameworkException
{
    public function __construct(
        public readonly ModuleIdentifier $owner,
        Throwable $previous,
    ) {
        parent::__construct(
            message: 'Module service registration failed.',
            previous: $previous,
        );
    }
}
