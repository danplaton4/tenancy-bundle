<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Shared;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Shared\TenantEmSwitcher;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for TenantEmSwitcher (W-02 extraction).
 *
 * Verifies byte-identical behavior: setTenant → close → resetManager on switchTo;
 * set-or-clear → close → resetManager on restore.
 */
final class TenantEmSwitcherTest extends TestCase
{
    private TenantContext $tenantContext;
    private ManagerRegistry&MockObject $registry;
    private Connection&MockObject $tenantConn;
    private EntityManagerInterface&MockObject $tenantEm;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->tenantConn = $this->createMock(Connection::class);
        $this->tenantEm = $this->createMock(EntityManagerInterface::class);
    }

    private function makeSwitcher(): TenantEmSwitcher
    {
        return new TenantEmSwitcher($this->tenantContext, $this->registry);
    }

    private function makeTenant(string $slug = 'acme'): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }

    /**
     * switchTo(): setTenant() is called, Connection::close() is called once,
     * resetManager('tenant') is called, and the returned EM is the reset one.
     */
    public function testSwitchToSetsContextClosesConnectionAndResetsManager(): void
    {
        $tenant = $this->makeTenant('acme');

        $this->registry
            ->method('getConnection')
            ->with('tenant')
            ->willReturn($this->tenantConn);

        // CR-02: close() MUST be called exactly once on the tenant connection
        $this->tenantConn
            ->expects($this->once())
            ->method('close');

        // resetManager('tenant') MUST be called and returns the fresh EM
        $this->registry
            ->expects($this->once())
            ->method('resetManager')
            ->with('tenant')
            ->willReturn($this->tenantEm);

        $switcher = $this->makeSwitcher();
        $result = $switcher->switchTo($tenant);

        // CR-01: tenant context must reflect the switched tenant
        $this->assertTrue($this->tenantContext->hasTenant());
        $this->assertSame($tenant, $this->tenantContext->getTenant());

        // The method must return the fresh EM from resetManager
        $this->assertSame($this->tenantEm, $result);
    }

    /**
     * restore(null): TenantContext::clear() is called (hasTenant() false after),
     * close() and resetManager('tenant') are still called.
     */
    public function testRestoreWithNullClearsTenantContext(): void
    {
        // Seed a tenant first
        $existingTenant = $this->makeTenant('existing');
        $this->tenantContext->setTenant($existingTenant);

        $this->registry
            ->method('getConnection')
            ->with('tenant')
            ->willReturn($this->tenantConn);

        $this->tenantConn
            ->expects($this->once())
            ->method('close');

        $this->registry
            ->expects($this->once())
            ->method('resetManager')
            ->with('tenant');

        $switcher = $this->makeSwitcher();
        $switcher->restore(null);

        // CR-01: null previousTenant → context must be cleared
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    /**
     * restore($tenant): TenantContext::setTenant($tenant) is called (hasTenant() true after),
     * close() and resetManager('tenant') are still called.
     */
    public function testRestoreWithTenantReinstatesTenantContext(): void
    {
        $previousTenant = $this->makeTenant('previous');

        $this->registry
            ->method('getConnection')
            ->with('tenant')
            ->willReturn($this->tenantConn);

        $this->tenantConn
            ->expects($this->once())
            ->method('close');

        $this->registry
            ->expects($this->once())
            ->method('resetManager')
            ->with('tenant');

        $switcher = $this->makeSwitcher();
        $switcher->restore($previousTenant);

        // CR-01: non-null previousTenant → context must reflect it
        $this->assertTrue($this->tenantContext->hasTenant());
        $this->assertSame($previousTenant, $this->tenantContext->getTenant());
    }

    /**
     * switchTo() + restore() sequence: full round-trip mimics the fan-out lifecycle.
     * Proves CR-01 (save/restore) and CR-02 (close on every switch and restore).
     */
    public function testSwitchAndRestoreRoundTrip(): void
    {
        $originalTenant = $this->makeTenant('original');
        $fanOutTenant = $this->makeTenant('fanout');

        $this->tenantContext->setTenant($originalTenant);

        $this->registry
            ->method('getConnection')
            ->with('tenant')
            ->willReturn($this->tenantConn);

        // Two close() calls: one in switchTo, one in restore
        $this->tenantConn
            ->expects($this->exactly(2))
            ->method('close');

        // Two resetManager calls: one in switchTo, one in restore
        $this->registry
            ->expects($this->exactly(2))
            ->method('resetManager')
            ->with('tenant')
            ->willReturn($this->tenantEm);

        $switcher = $this->makeSwitcher();

        // Fan-out switch
        $em = $switcher->switchTo($fanOutTenant);
        $this->assertSame($fanOutTenant, $this->tenantContext->getTenant());
        $this->assertSame($this->tenantEm, $em);

        // Restore original context
        $switcher->restore($originalTenant);
        $this->assertTrue($this->tenantContext->hasTenant());
        $this->assertSame($originalTenant, $this->tenantContext->getTenant());
    }
}
