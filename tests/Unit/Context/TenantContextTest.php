<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

final class TenantContextTest extends TestCase
{
    public function testInitiallyHasNoTenant(): void
    {
        $context = new TenantContext();

        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenant());
    }

    public function testSetTenantStoresAndReturns(): void
    {
        $context = new TenantContext();
        $tenant = $this->createMock(TenantInterface::class);

        $context->setTenant($tenant);

        $this->assertTrue($context->hasTenant());
        $this->assertSame($tenant, $context->getTenant());
    }

    public function testClearResetsTenantToNull(): void
    {
        $context = new TenantContext();
        $tenant = $this->createMock(TenantInterface::class);

        $context->setTenant($tenant);
        $context->clear();

        $this->assertFalse($context->hasTenant());
        $this->assertNull($context->getTenant());
    }

    public function testSetTenantOverwritesPrevious(): void
    {
        $context = new TenantContext();
        $tenantA = $this->createMock(TenantInterface::class);
        $tenantB = $this->createMock(TenantInterface::class);

        $context->setTenant($tenantA);
        $context->setTenant($tenantB);

        $this->assertSame($tenantB, $context->getTenant());
    }

    public function testHasZeroConstructorParameters(): void
    {
        $reflection = new \ReflectionClass(TenantContext::class);
        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            $this->assertTrue(true);

            return;
        }

        $this->assertSame(0, $constructor->getNumberOfParameters());
    }
}
