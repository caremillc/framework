<?php

declare(strict_types=1);

namespace Careminate\Exception;

use Throwable;

/**
 * Marker implemented by every exception owned by the Careminate framework.
 */
interface ExceptionInterface extends Throwable
{
}