<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException;
use Tenancy\Bundle\Shared\SharedEntityCopierInterface;
use Tenancy\Bundle\Subscriber\SharedEntityWriteProtectionListener;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;

/**
 * Unit tests for SharedEntityWriteProtectionListener (D-07 W-01 mock-copier seam).
 *
 * Injects a mock SharedEntityCopierInterface to prove:
 *  1. Re-entrancy bypass: isSyncInProgress() true → no throw even with #[Shared] entity scheduled.
 *  2. Throw-on-Shared-write: isSyncInProgress() false, tenant active, #[Shared] entity → throws.
 *  3. (Bonus) No-tenant bypass: hasTenant() false → returns without inspecting copier or scheduled sets.
 */
final class SharedEntityWriteProtectionListenerTest extends TestCase
{
    private SharedEntityCopierInterface&MockObject $copier;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->copier = $this->createMock(SharedEntityCopierInterface::class);
        $this->tenantContext = new TenantContext();
    }

    /**
     * Build a minimal OnFlushEventArgs with a #[Shared] entity in scheduled insertions.
     *
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param list<object> $deletions
     */
    private function buildOnFlushArgs(
        array $insertions = [],
        array $updates = [],
        array $deletions = [],
    ): OnFlushEventArgs {
        // ClassMetadata whose reflClass is a ReflectionClass of TestPlan (carries #[Shared])
        /** @var ClassMetadata<object>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->reflClass = new \ReflectionClass(TestPlan::class);

        /** @var UnitOfWork&MockObject $uow */
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getScheduledEntityDeletions')->willReturn($deletions);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new OnFlushEventArgs($em);
    }

    private function makeTenant(string $slug = 'acme'): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }

    /**
     * D-07 Test 1 — Re-entrancy bypass:
     * isSyncInProgress() returns true → onFlush returns WITHOUT throwing,
     * even when a #[Shared] entity is scheduled AND a tenant is active.
     */
    public function testReentrancyBypassAllowsCopierOriginatedFlush(): void
    {
        // Arrange: copier is mid-sync
        $this->copier->method('isSyncInProgress')->willReturn(true);

        // A tenant IS active
        $this->tenantContext->setTenant($this->makeTenant());

        // A #[Shared] entity IS scheduled
        $plan = new TestPlan('Bypass Plan', 100);
        $args = $this->buildOnFlushArgs([$plan]);

        $listener = new SharedEntityWriteProtectionListener($this->tenantContext, $this->copier);

        // Act + Assert: no exception thrown (copier-originated sync write allowed through)
        $listener->onFlush($args);

        // Positive assertion: we reached this point without throwing
        $this->assertTrue(true, 'onFlush must return without throwing when isSyncInProgress() is true');
    }

    /**
     * D-07 Test 2 — Throw-on-Shared-write:
     * Tenant active, isSyncInProgress() false, #[Shared] entity in scheduled insertions
     * → onFlush throws SharedEntityWriteInTenantContextException.
     */
    public function testSharedEntityWriteInTenantContextThrows(): void
    {
        // Arrange: NOT mid-sync
        $this->copier->method('isSyncInProgress')->willReturn(false);

        // A tenant IS active
        $this->tenantContext->setTenant($this->makeTenant('acme'));

        // A #[Shared] entity IS scheduled for insertion
        $plan = new TestPlan('Protected Plan', 500);
        $args = $this->buildOnFlushArgs([$plan]);

        $listener = new SharedEntityWriteProtectionListener($this->tenantContext, $this->copier);

        // Act + Assert: must throw
        $this->expectException(SharedEntityWriteInTenantContextException::class);
        $listener->onFlush($args);
    }

    /**
     * Bonus Test 3 — No-tenant bypass:
     * hasTenant() false → onFlush returns immediately without consulting copier or scheduled sets.
     */
    public function testNoTenantBypassSkipsGuard(): void
    {
        // No tenant active
        $this->assertFalse($this->tenantContext->hasTenant());

        // Copier's isSyncInProgress() must NOT be called (no-tenant bypass fires first)
        $this->copier->expects($this->never())->method('isSyncInProgress');

        // A #[Shared] entity is scheduled — should be irrelevant
        $plan = new TestPlan('No-tenant Plan', 0);
        $args = $this->buildOnFlushArgs([$plan]);

        $listener = new SharedEntityWriteProtectionListener($this->tenantContext, $this->copier);
        $listener->onFlush($args);

        $this->assertTrue(true, 'onFlush must return without inspecting copier when no tenant is active');
    }
}
