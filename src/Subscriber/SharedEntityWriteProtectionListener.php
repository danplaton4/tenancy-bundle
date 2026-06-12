<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException;

/**
 * Tenant-EM Doctrine event listener that enforces full read-only access to #[Shared]
 * entities from the tenant side (D-02).
 *
 * When a tenant context is active and a user attempts to insert, update, or delete a
 * #[Shared] entity via the tenant EntityManager, this listener throws
 * SharedEntityWriteInTenantContextException in onFlush — before any SQL is executed.
 *
 * ## Two bypass guards (applied in order before inspecting scheduled sets)
 *
 * 1. Landlord context guard: if no tenant is active (hasTenant() === false), the write
 *    originates from the landlord side — the subscriber is wired to the tenant EM only, but
 *    this guard provides belt-and-suspenders safety.
 *
 * 2. Re-entrancy guard: if SharedEntitySyncSubscriber::isSyncInProgress() is true, the
 *    write originates from the subscriber's own fan-out flush — not user code. The guard
 *    must bypass in this case, otherwise the subscriber's own $tenantEm->flush() would
 *    immediately throw (Pitfall 2 in 25-RESEARCH.md).
 *
 * ## Must NOT use #[AsEventListener] or autoconfigure
 *
 * Registered exclusively via doctrine.event_listener tag (connection: tenant) in
 * config/services.php (Plan 25-04). Using autoconfigure would wire Symfony kernel event
 * tags instead of Doctrine event tags.
 */
final class SharedEntityWriteProtectionListener implements EventSubscriber
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SharedEntitySyncSubscriber $syncSubscriber,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }

    /**
     * Block #[Shared] entity writes in tenant context (D-02, T-25-01).
     *
     * Guard order (mirrors TenantContextOrchestrator early-return guard style):
     *   1. No tenant active → landlord context, bypass (no protection needed).
     *   2. Sync in progress → subscriber's own fan-out flush, bypass (re-entrancy, D-02).
     *   3. Inspect scheduled sets → throw on any #[Shared] entity found.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        // Bypass: no tenant active — landlord context or console without tenant resolver
        if (!$this->tenantContext->hasTenant()) {
            return;
        }

        // Bypass: this is a subscriber-originated sync write (re-entrancy guard, Pitfall 2)
        if ($this->syncSubscriber->isSyncInProgress()) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $tenant = $this->tenantContext->getTenant();

        if (null === $tenant) {
            return;
        }

        foreach ([
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
            $uow->getScheduledEntityDeletions(),
        ] as $entities) {
            foreach ($entities as $entity) {
                // WR-01: reflect the REAL mapped class via Doctrine metadata
                // (ClassMetadata::$reflClass), NOT `new \ReflectionClass($entity)`. A classic
                // Doctrine lazy-loading proxy reflects the proxy subclass, and PHP class
                // attributes are not inherited — so reflecting the runtime object would return
                // [] for getAttributes(Shared::class) and silently let a proxy-backed #[Shared]
                // write through the guard (a write-protection bypass). Mirrors TenantAwareFilter.
                $refl = $em->getClassMetadata($entity::class)->reflClass;
                if (null !== $refl && [] !== $refl->getAttributes(Shared::class)) {
                    throw SharedEntityWriteInTenantContextException::forEntity($entity::class, $tenant->getSlug());
                }
            }
        }
    }
}
