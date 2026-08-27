<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Exception\Module\ModuleBoundaryViolationException;
use Careminate\Module\ServiceOwnershipRegistry;
use PHPUnit\Framework\TestCase;

final class ServiceOwnershipRegistryTest extends TestCase
{
    public function test_another_module_cannot_take_over_a_service(): void
    {
        $ownership = new ServiceOwnershipRegistry();
        $ownership->claimService('payment.gateway', 'billing');

        $this->expectException(ModuleBoundaryViolationException::class);

        $ownership->claimService('payment.gateway', 'reporting');
    }

    public function test_a_module_can_replace_its_own_service(): void
    {
        $ownership = new ServiceOwnershipRegistry();
        $ownership->claimService('payment.gateway', 'billing');

        $ownership->assertReplaceable('payment.gateway', 'billing');

        self::assertSame(
            'billing',
            $ownership->ownerOf('payment.gateway'),
        );
    }
}
