<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Landlord-EM Doctrine event subscriber that fans #[Shared] entity changes to every
 * tenant EntityManager on postFlush (D-01 best-effort / D-05 insert+update+delete).
 *
 * ## CRITICAL: buffer in onFlush, apply in postFlush
 *
 * UnitOfWork::executeInserts() / executeUpdates() / executeDeletions() each call
 * unset() on their respective scheduled-entity arrays as they process each entity.
 * By the time postFlush fires (UnitOfWork.php line 471), all three arrays are EMPTY.
 * This subscriber buffers #[Shared] changesets in onFlush (when the arrays are FULL)
 * and stores them on the subscriber instance; postFlush iterates the buffer.
 *
 * ## One-level cascade boundary (DEC-SHARE-02) — DOCUMENTED LANDMINE
 *
 * doSync() copies getFieldNames() (scalar fields) ONLY. Association fields returned
 * by getAssociationNames() are intentionally skipped. If a #[Shared] entity carries a
 * ManyToOne or OneToOne association to a non-#[Shared] entity, the association will be
 * NULL on the tenant side. The associated entity is NOT synced unless it also carries
 * #[Shared]. Design your shared entities to be self-contained (scalar fields only), or
 * ensure that all associated entities referenced from a #[Shared] entity also carry
 * #[Shared] so their own sync run creates the record before this association is read.
 *
 * ## shared_db short-circuit (D-03)
 *
 * Under the shared_db driver there are no per-tenant EntityManagers. The subscriber
 * short-circuits when driver === 'shared_db' — findAll() is NEVER called; the buffer is
 * cleared and the method returns immediately.
 *
 * ## Re-entrancy guard
 *
 * The subscriber's own $tenantEm->flush() triggers onFlush on the tenant EM. Without a
 * guard, SharedEntityWriteProtectionListener would see the #[Shared] entity in the
 * scheduled insertions and throw SharedEntityWriteInTenantContextException. The
 * $syncInProgress flag allows the write-protection listener to bypass the guard for
 * subscriber-originated writes.
 *
 * ## Must NOT use #[AsEventListener] or autoconfigure
 *
 * Registered exclusively via doctrine.event_listener tags (connection: landlord) in
 * config/services.php (Plan 25-04). Using autoconfigure or #[AsEventListener] would
 * wire Symfony kernel event tags instead.
 */
final class SharedEntitySyncSubscriber implements EventSubscriber
{
    /** @var array<int, array{entity: object, type: 'insert'|'update'|'delete'}> */
    private array $pendingChanges = [];

    private bool $syncInProgress = false;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly string $driver,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

    /**
     * Buffer #[Shared] entity changesets while the UnitOfWork arrays are still populated.
     *
     * Called BEFORE executeInserts/Updates/Deletions drain the scheduled-entity arrays.
     * postFlush would see empty arrays — we must buffer here.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->isShared($entity)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'insert'];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isShared($entity)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'update'];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isShared($entity)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'delete'];
            }
        }
    }

    /**
     * Fan out buffered #[Shared] changesets to every tenant EM (best-effort, D-01).
     *
     * Short-circuits immediately when driver === 'shared_db' (D-03): findAll() is NOT called.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingChanges) {
            return;
        }

        if ('shared_db' === $this->driver) {
            // D-03: shared_db has no per-tenant EMs — documented no-op
            $this->pendingChanges = [];

            return;
        }

        $changes = $this->pendingChanges;
        $this->pendingChanges = [];

        foreach ($changes as ['entity' => $entity, 'type' => $type]) {
            foreach ($this->tenantProvider->findAll() as $tenant) {
                $this->fanOutToTenant($args->getObjectManager(), $entity, $type, $tenant);
            }
        }
    }

    /**
     * Whether the subscriber is currently writing to a tenant EM (re-entrancy flag).
     *
     * SharedEntityWriteProtectionListener calls this to bypass the write-protection guard
     * when the write originates from this subscriber's own sync operation (not user code).
     */
    public function isSyncInProgress(): bool
    {
        return $this->syncInProgress;
    }

    /**
     * Apply a single entity change to one tenant EM (best-effort, D-01 / D-07).
     *
     * try/catch/finally ensures:
     *   - Failures are caught, logged, and never rethrown (D-01 — landlord request unaffected).
     *   - TenantContext is always cleared in the finally block (Pitfall 4 — no stale context).
     */
    private function fanOutToTenant(
        EntityManagerInterface $landlordEm,
        object $entity,
        string $type,
        TenantInterface $tenant,
    ): void {
        try {
            $this->tenantContext->setTenant($tenant);
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $this->registry->resetManager('tenant');
            $this->syncInProgress = true;
            $this->doSync($landlordEm, $tenantEm, $entity, $type);
            $this->syncInProgress = false;
        } catch (\Throwable $e) {
            $this->syncInProgress = false;
            $meta = $landlordEm->getClassMetadata($entity::class);
            $this->logger->warning('tenancy.shared_entity_sync_failed', [
                'tenant_slug' => $tenant->getSlug(),
                'entity_class' => $entity::class,
                'identifier' => $meta->getIdentifierValues($entity),
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->tenantContext->clear();
        }
    }

    /**
     * Upsert or delete a #[Shared] entity on one tenant EM.
     *
     * Uses find-or-new + ClassMetadata field copy — NOT merge() (removed in ORM 3.0).
     * Copies getFieldNames() (scalar fields) ONLY — getAssociationNames() is never iterated
     * (one-level cascade boundary, DEC-SHARE-02). See class docblock for the landmine.
     */
    private function doSync(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        object $entity,
        string $type,
    ): void {
        $class = $entity::class;
        $landlordMeta = $landlordEm->getClassMetadata($class);
        $ids = $landlordMeta->getIdentifierValues($entity);

        if ('delete' === $type) {
            $existing = $tenantEm->find($class, $ids);
            if (null !== $existing) {
                $tenantEm->remove($existing);
                $tenantEm->flush();
            }

            return;
        }

        // insert or update: find-or-new + scalar field copy
        $existing = $tenantEm->find($class, $ids);
        $tenantMeta = $tenantEm->getClassMetadata($class);

        if (null === $existing) {
            $copy = $tenantMeta->newInstance();
        } else {
            $copy = $existing;
        }

        // Copy scalar fields only — associations are intentionally skipped (DEC-SHARE-02)
        // getFieldNames() returns only scalar/column-mapped fields, NOT association fields.
        // DO NOT iterate getAssociationNames() — that breaks the one-level cascade boundary.
        foreach ($landlordMeta->getFieldNames() as $fieldName) {
            $value = $landlordMeta->getFieldValue($entity, $fieldName);
            $tenantMeta->setFieldValue($copy, $fieldName, $value);
        }

        $tenantEm->persist($copy);
        $tenantEm->flush();
    }

    /**
     * Returns true when the entity carries the #[Shared] attribute.
     */
    private function isShared(object $entity): bool
    {
        return [] !== (new \ReflectionClass($entity))->getAttributes(Shared::class);
    }
}
