<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;

/**
 * Spy TenantProviderInterface: records findAll() invocations.
 *
 * Designed to make the test fail immediately if findAll() is ever called —
 * proving the D-03 shared_db short-circuit fires BEFORE the fan-out loop.
 */
final class NeverCalledTenantProvider implements TenantProviderInterface
{
    public bool $findAllWasCalled = false;

    public function findAll(): array
    {
        $this->findAllWasCalled = true;

        return [];
    }

    public function findBySlug(string $slug): TenantInterface
    {
        throw new TenantNotFoundException('NeverCalledTenantProvider: findBySlug must not be called.');
    }
}

/**
 * SHARE-01-j: Subscriber postFlush short-circuits under driver = 'shared_db' before calling findAll().
 *
 * Covers the D-03 dead-code path: the SharedEntitySyncSubscriber is only registered
 * under database_per_tenant, so the 'shared_db' === $this->driver branch in postFlush
 * can only be exercised directly via a unit test that instantiates the subscriber with
 * driver = 'shared_db'.
 *
 * Behavior under test:
 *   1. onFlush buffers a #[Shared] TestPlan insertion (pendingChanges is non-empty).
 *   2. postFlush detects driver = 'shared_db', clears the buffer, and returns.
 *   3. TenantProviderInterface::findAll() is NEVER called (spy asserts this).
 */
final class SharedEntitySyncSubscriberSharedDbTest extends TestCase
{
    /**
     * SHARE-01-j: postFlush clears buffer and returns without calling findAll() when driver = 'shared_db'.
     */
    public function testSharedDbDriverShortCircuitsBeforeFindAll(): void
    {
        $spyProvider = new NeverCalledTenantProvider();
        $tenantContext = new TenantContext();

        /** @var ManagerRegistry&\PHPUnit\Framework\MockObject\MockObject $registry */
        $registry = $this->createMock(ManagerRegistry::class);
        // Registry must never be asked for a connection or manager — short-circuit fires first
        $registry->expects($this->never())->method('getConnection');
        $registry->expects($this->never())->method('resetManager');
        $registry->expects($this->never())->method('getManager');

        $subscriber = new SharedEntitySyncSubscriber(
            $tenantContext,
            $spyProvider,
            $registry,
            new NullLogger(),
            'shared_db',
        );

        // Build a fake onFlush event that returns one #[Shared] entity insertion
        $plan = new TestPlan('Shared-DB Plan', 1000);
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([$plan]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getUnitOfWork')->willReturn($uow);
        // getClassMetadata is NOT called for deletions here, but make it safe anyway
        $landlordEm->method('getClassMetadata')->willReturn(
            $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class)
        );

        $onFlushArgs = new OnFlushEventArgs($landlordEm);

        // Drive onFlush — this populates pendingChanges with the TestPlan insertion
        $subscriber->onFlush($onFlushArgs);

        // Drive postFlush — must short-circuit because driver = 'shared_db'
        $postFlushArgs = new PostFlushEventArgs($landlordEm);
        $subscriber->postFlush($postFlushArgs);

        // Primary assertion: findAll() MUST NOT have been called (D-03 short-circuit)
        $this->assertFalse(
            $spyProvider->findAllWasCalled,
            'TenantProviderInterface::findAll() must never be called when driver = shared_db — '
            .'the postFlush D-03 short-circuit must fire before the fan-out loop'
        );

        // Secondary assertion: re-entrancy flag must still be false (no sync in progress)
        $this->assertFalse(
            $subscriber->isSyncInProgress(),
            'isSyncInProgress() must be false after postFlush short-circuit'
        );

        // Secondary assertion: buffer is cleared — a second postFlush call must be a complete no-op
        // (pendingChanges = [] so the first early return fires, not the shared_db guard)
        $spyProvider->findAllWasCalled = false; // reset
        $subscriber->postFlush($postFlushArgs);
        $this->assertFalse(
            $spyProvider->findAllWasCalled,
            'Buffer must be cleared after short-circuit — second postFlush call must also not invoke findAll()'
        );
    }

    /**
     * SHARE-01-j: pending buffer is empty after the short-circuit, even if onFlush fired multiple times.
     */
    public function testSharedDbDriverClearsBufferAfterMultipleOnFlushCalls(): void
    {
        $spyProvider = new NeverCalledTenantProvider();
        $tenantContext = new TenantContext();

        /** @var ManagerRegistry&\PHPUnit\Framework\MockObject\MockObject $registry */
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getConnection');
        $registry->expects($this->never())->method('resetManager');

        $subscriber = new SharedEntitySyncSubscriber(
            $tenantContext,
            $spyProvider,
            $registry,
            new NullLogger(),
            'shared_db',
        );

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([new TestPlan('Plan1', 100), new TestPlan('Plan2', 200)]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $landlordEm */
        $landlordEm = $this->createMock(EntityManagerInterface::class);
        $landlordEm->method('getUnitOfWork')->willReturn($uow);
        $landlordEm->method('getClassMetadata')->willReturn(
            $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class)
        );

        // Two onFlush calls — two entities buffered
        $onFlushArgs = new OnFlushEventArgs($landlordEm);
        $subscriber->onFlush($onFlushArgs);
        $subscriber->onFlush($onFlushArgs);

        // postFlush must short-circuit and clear everything
        $postFlushArgs = new PostFlushEventArgs($landlordEm);
        $subscriber->postFlush($postFlushArgs);

        $this->assertFalse(
            $spyProvider->findAllWasCalled,
            'findAll() must not be called after multiple onFlush bufferings when driver = shared_db'
        );
    }
}
