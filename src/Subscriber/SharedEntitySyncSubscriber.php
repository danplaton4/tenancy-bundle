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
    /**
     * Pending changeset buffer.
     *
     * For insert/update: the entity reference itself is sufficient — the ID is available at
     * postFlush time because Doctrine does NOT null the identifier on insert/update.
     *
     * For delete: we MUST also capture the identifier in onFlush while it is still set.
     * Doctrine ORM zeroes the entity's identifier field in executeDeletions() (before postFlush
     * fires) — getIdentifierValues() returns [] by the time postFlush runs.
     * The captured identifier is passed directly to $tenantEm->find() in doSync.
     *
     * @var array<int, array{entity: object, type: 'insert'|'update'|'delete', ids?: array<string, mixed>}>
     */
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
            if ($this->isShared($entity, $em)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'insert'];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isShared($entity, $em)) {
                $this->pendingChanges[spl_object_id($entity)] = ['entity' => $entity, 'type' => 'update'];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isShared($entity, $em)) {
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

        try {
            foreach ($changes as $change) {
                $entity = $change['entity'];
                $type = $change['type'];
                // Pre-captured identifier for deletes (entity ID zeroed by Doctrine before postFlush)
                $capturedIds = $change['ids'] ?? null;

                foreach ($this->tenantProvider->findAll() as $tenant) {
                    $this->fanOutToTenant($args->getObjectManager(), $entity, $type, $tenant, $capturedIds);
                }
            }
        } finally {
            $this->restoreTenantContext($previousTenant);
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
     *   - Failures are caught, logged at error level, and never rethrown (D-01 — landlord request unaffected).
     *   - The $syncInProgress re-entrancy flag is always reset (WR-02), even if doSync throws.
     *
     * The pre-fan-out TenantContext is NOT restored here — that is owned centrally by
     * postFlush()/restoreTenantContext() so the original request-scoped tenant survives the
     * whole loop (CR-01), not just one iteration.
     *
     * @param array<string, mixed>|null $capturedIds pre-captured entity identifier (required for
     *                                               deletes — Doctrine zeroes ID fields before postFlush)
     */
    private function fanOutToTenant(
        EntityManagerInterface $landlordEm,
        object $entity,
        string $type,
        TenantInterface $tenant,
        ?array $capturedIds = null,
    ): void {
        try {
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
            $this->syncInProgress = true;
            $this->doSync($landlordEm, $tenantEm, $entity, $type, $capturedIds);
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
        } finally {
            // WR-02: always reset the re-entrancy flag, even if doSync threw mid-flush.
            $this->syncInProgress = false;
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

    /**
     * Upsert or delete a #[Shared] entity on one tenant EM.
     *
     * Uses find-or-new + ClassMetadata field copy — NOT merge() (removed in ORM 3.0).
     * Copies getFieldNames() (scalar fields) ONLY — getAssociationNames() is never iterated
     * (one-level cascade boundary, DEC-SHARE-02). See class docblock for the landmine.
     *
     * @param array<string, mixed>|null $capturedIds pre-captured identifier for deletes —
     *                                               Doctrine zeroes entity ID fields before postFlush
     */
    private function doSync(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        object $entity,
        string $type,
        ?array $capturedIds = null,
    ): void {
        $class = $entity::class;
        $landlordMeta = $landlordEm->getClassMetadata($class);
        // For deletes, use the pre-captured IDs (Doctrine zeroes identifier fields in executeDeletions
        // before postFlush fires). For insert/update, capture from the entity directly.
        $ids = 'delete' === $type ? ($capturedIds ?? $landlordMeta->getIdentifierValues($entity)) : $landlordMeta->getIdentifierValues($entity);

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

        $isInsert = null === $existing;
        $copy = $isInsert ? $tenantMeta->newInstance() : $existing;

        // Copy scalar fields only — associations are intentionally skipped (DEC-SHARE-02)
        // getFieldNames() returns only scalar/column-mapped fields, NOT association fields.
        // DO NOT iterate getAssociationNames() — that breaks the one-level cascade boundary.
        // This INCLUDES the identifier field(s), so the landlord's id is copied onto $copy.
        foreach ($landlordMeta->getFieldNames() as $fieldName) {
            $value = $landlordMeta->getFieldValue($entity, $fieldName);
            $tenantMeta->setFieldValue($copy, $fieldName, $value);
        }

        if ($isInsert) {
            // CR-01: the tenant copy MUST carry the SAME primary key as the landlord master —
            // that invariant is what the update/delete paths rely on (they look the copy up by
            // the landlord id). A shared entity typically maps #[ORM\GeneratedValue] (IDENTITY),
            // a post-insert generator: it OMITS the id column on INSERT and reads lastInsertId()
            // afterward, discarding the id we just copied onto $copy and letting each tenant DB
            // assign its own auto-increment value. Master and copy keys would then stay equal
            // only while both DBs' sequences happen to be in lockstep — and diverge permanently
            // the moment a tenant has any independent write history. Forcing the id generator to
            // NONE for this synced insert makes the copied landlord id authoritative: the INSERT
            // emits the id column verbatim, preserving the cross-DB key equality the sync depends
            // on. Only flip it when the entity actually uses a post-insert generator, so natural /
            // assigned-id entities are unaffected.
            // isset() guards the typed-but-possibly-uninitialized $idGenerator property; the
            // ?-> null-safe operator alone does not cover an uninitialized typed property.
            if ($tenantMeta->isIdGeneratorIdentity()
                || (isset($tenantMeta->idGenerator) && $tenantMeta->idGenerator->isPostInsertGenerator())) {
                $tenantMeta->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            }
        }

        $tenantEm->persist($copy);
        $tenantEm->flush();
    }

    /**
     * Returns true when the entity carries the #[Shared] attribute.
     *
     * WR-01: resolve the attribute against the REAL mapped class via Doctrine metadata
     * (ClassMetadata::$reflClass), NOT `new \ReflectionClass($entity)`. When $entity is a
     * classic Doctrine lazy-loading proxy (Proxies\__CG__\...), reflecting the runtime object
     * reflects the proxy subclass; PHP class attributes are not inherited, so getAttributes()
     * would return [] and a proxy-backed #[Shared] entity would be silently skipped. This
     * mirrors TenantAwareFilter, which deliberately reflects $targetEntity->reflClass.
     */
    private function isShared(object $entity, EntityManagerInterface $em): bool
    {
        $refl = $em->getClassMetadata($entity::class)->reflClass;

        return null !== $refl && [] !== $refl->getAttributes(Shared::class);
    }
}
