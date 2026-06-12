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
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Shared\SharedEntityCopier;
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
 * SharedEntityCopier::applyRow() copies getFieldNames() (scalar fields) ONLY. Association
 * fields returned by getAssociationNames() are intentionally skipped. If a #[Shared] entity
 * carries a ManyToOne or OneToOne association to a non-#[Shared] entity, the association will
 * be NULL on the tenant side. The associated entity is NOT synced unless it also carries
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
 * SharedEntityCopier::applyRow() sets the syncInProgress flag immediately before
 * $tenantEm->flush() and resets it in a finally. SharedEntityWriteProtectionListener
 * consults SharedEntityCopier::isSyncInProgress() to bypass the guard for sync writes.
 *
 * ## Must NOT use #[AsEventListener] or autoconfigure
 *
 * Registered exclusively via doctrine.event_listener tags (connection: landlord) in
 * config/services.php (Plan 25-04). Using autoconfigure or #[AsEventListener] would
 * wire Symfony kernel event tags instead.
 */
final class SharedEntitySyncSubscriber implements EventSubscriber
{
    /**
     * Pending changeset buffer.
     *
     * For insert/update: the entity reference itself is sufficient — the ID is available at
     * postFlush time because Doctrine does NOT null the identifier on insert/update.
     *
     * For delete: we MUST also capture the identifier in onFlush while it is still set.
     * Doctrine ORM zeroes the entity's identifier field in executeDeletions() (before postFlush
     * fires) — getIdentifierValues() returns [] by the time postFlush runs.
     * The captured identifier is passed directly to SharedEntityCopier::applyRow().
     *
     * @var array<int, array{entity: object, type: 'insert'|'update'|'delete', ids?: array<string, mixed>}>
     */
    private array $pendingChanges = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly string $driver,
        private readonly SharedEntityCopier $copier,
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
     *
     * For deletions, we also capture the entity identifier right now, because Doctrine ORM
     * zeroes the identifier field in executeDeletions() (before postFlush fires).
     * By postFlush time, getIdentifierValues() returns [] — we need the captured ids.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->copier->isShared($entity, $em)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'insert'];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->copier->isShared($entity, $em)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'update'];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->copier->isShared($entity, $em)) {
                // Capture the identifier NOW — Doctrine zeroes identifier fields during
                // executeDeletions() so getIdentifierValues() returns [] in postFlush.
                /** @var array<string, mixed> $ids */
                $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
                $this->pendingChanges[spl_object_id($entity)] = [
                    'entity' => $entity,
                    'type' => 'delete',
                    'ids' => $ids,
                ];
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

        // CR-01: capture the tenant active before fan-out. The fan-out loop repeatedly switches
        // TenantContext per tenant; without save/restore the request-scoped tenant that was
        // active when the landlord flush occurred would be lost — causing TenantMissingException
        // or (strict_mode off) unscoped cross-tenant queries for the rest of the request. The
        // finally below re-instates it after every tenant has been fanned out.
        $previousTenant = $this->tenantContext->hasTenant() ? $this->tenantContext->getTenant() : null;

        // WR-04: materialize the tenant list ONCE. The loop is tenant→change, not change→tenant,
        // so findAll() is iterated a single time — a provider that returns a Generator (or lazily
        // queries) would otherwise be exhausted after the first change and silently skip fan-out
        // for every later change. Iterating outer-by-tenant also lets us reset each tenant EM only
        // once per tenant (not once per entity), so a later shared entity in the same flush can
        // see an earlier-synced one in the warm identity map.
        $tenants = [];
        foreach ($this->tenantProvider->findAll() as $tenant) {
            $tenants[] = $tenant;
        }

        $landlordEm = $args->getObjectManager();

        try {
            foreach ($tenants as $tenant) {
                $tenantEm = $this->switchToTenant($tenant);

                foreach ($changes as $change) {
                    // applyChange returns the EM to use for the NEXT change: a failed flush
                    // closes the Doctrine EM, so on error it hands back a freshly reset EM so
                    // the remaining changes for this tenant are not all dragged down with it.
                    // The re-entrancy flag is owned per-flush by SharedEntityCopier::applyRow().
                    $tenantEm = $this->applyChange($landlordEm, $tenantEm, $tenant, $change);
                }
            }
        } finally {
            $this->restoreTenantContext($previousTenant);
        }
    }

    /**
     * Switch TenantContext to $tenant and return a fresh tenant EM bound to that tenant's DB.
     *
     * WR-04: called ONCE per tenant (not once per changed entity). Resetting the tenant EM once
     * per tenant keeps the identity map warm across all of that tenant's changes, so a later
     * shared entity in the same flush can resolve an earlier-synced one.
     */
    private function switchToTenant(TenantInterface $tenant): EntityManagerInterface
    {
        $this->tenantContext->setTenant($tenant);

        // Force the tenant DBAL connection to reconnect via TenantAwareDriver::connect()
        // so it picks up the new tenant's connection params. Without close(), the
        // previously-open socket stays connected to the prior tenant's DB (DBAL only
        // calls connect() when the internal connection handle is null).
        $tenantConn = $this->registry->getConnection('tenant');
        if ($tenantConn instanceof \Doctrine\DBAL\Connection) {
            $tenantConn->close();
        }

        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $this->registry->resetManager('tenant');

        return $tenantEm;
    }

    /**
     * Apply a single buffered change to one already-switched tenant EM (best-effort, D-01 / D-07).
     *
     * Failures are caught, logged at error level, and never rethrown (D-01 — landlord request
     * unaffected; one tenant's failure does not abort fan-out to the others). The re-entrancy flag
     * is owned per-flush by SharedEntityCopier::applyRow(), not here.
     *
     * The pre-fan-out TenantContext is NOT restored here — that is owned centrally by
     * postFlush()/restoreTenantContext() so the original request-scoped tenant survives the
     * whole loop (CR-01).
     *
     * @param array{entity: object, type: 'insert'|'update'|'delete', ids?: array<string, mixed>} $change
     *
     * @return EntityManagerInterface the EM to use for the next change of this tenant — the same
     *                                instance on success, a freshly reset one if the flush closed it
     */
    private function applyChange(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        TenantInterface $tenant,
        array $change,
    ): EntityManagerInterface {
        $entity = $change['entity'];
        $type = $change['type'];
        // Pre-captured identifier for deletes (entity ID zeroed by Doctrine before postFlush).
        $capturedIds = $change['ids'] ?? null;

        try {
            $this->copier->applyRow($landlordEm, $tenantEm, $entity, $type, $capturedIds);

            return $tenantEm;
        } catch (\Throwable $e) {
            $meta = $landlordEm->getClassMetadata($entity::class);
            // For deletes, getIdentifierValues() returns [] (Doctrine zeroed it) — use captured IDs.
            $identifier = $capturedIds ?? $meta->getIdentifierValues($entity);
            // CR-03: the landlord transaction already committed in postFlush, so a failed tenant
            // flush leaves master and this tenant permanently diverged with no automatic repair
            // path. Log at error level — this is a data-integrity event, not a transient notice.
            $this->logger->error('tenancy.shared_entity_sync_failed', [
                'tenant_slug' => $tenant->getSlug(),
                'entity_class' => $entity::class,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            // A failed flush closes the Doctrine EM; reset it so the tenant's remaining changes
            // run against a usable manager instead of all throwing "EntityManager is closed".
            /** @var EntityManagerInterface $freshEm */
            $freshEm = $this->registry->resetManager('tenant');

            return $freshEm;
        }
    }

    /**
     * Restore the tenant context that was active before the fan-out and drop the tenant
     * connection handle so the next query reconnects under the restored context.
     *
     * CR-01: re-instates the request-scoped tenant (or clears if none was active) instead of
     * leaving the context wiped after the loop.
     * CR-02: the fan-out leaves the tenant DBAL connection open against the LAST tenant's DB
     * (close() only runs before each switch, never after the final one). Closing it here forces
     * TenantAwareDriver::connect() to re-resolve against the restored context on next use,
     * preventing later queries in the same request from silently hitting the wrong tenant's DB.
     */
    private function restoreTenantContext(?TenantInterface $previousTenant): void
    {
        if (null !== $previousTenant) {
            $this->tenantContext->setTenant($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        $tenantConn = $this->registry->getConnection('tenant');
        if ($tenantConn instanceof \Doctrine\DBAL\Connection) {
            $tenantConn->close();
        }
        $this->registry->resetManager('tenant');
    }
}
