<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Tenancy\Bundle\Attribute\Shared;

/**
 * Extracted shared-entity upsert service.
 *
 * Owns the find-or-new upsert path, the read-only classify/diff path, the
 * #[Shared] class enumeration, the proxy-safe isShared() check, and the
 * syncInProgress re-entrancy flag.
 *
 * ## Re-entrancy guard
 *
 * SharedEntityWriteProtectionListener consults isSyncInProgress() on this copier
 * to determine whether a tenant-EM flush originates from a legitimate sync write
 * (copier-owned) or from user code (must throw). The flag is set immediately before
 * $tenantEm->flush() and always reset in a finally — even if flush throws — so the
 * flag cannot leak permanently (RESEARCH Pitfall 1, T-26-02-FLAG).
 *
 * ## One-level cascade boundary (DEC-SHARE-02) — DOCUMENTED LANDMINE
 *
 * applyRow() copies getFieldNames() (scalar fields) ONLY. Association fields returned
 * by getAssociationNames() are intentionally skipped. If a #[Shared] entity carries a
 * ManyToOne or OneToOne association to a non-#[Shared] entity, the association will be
 * NULL on the tenant side. Design your shared entities to be self-contained (scalar
 * fields only), or ensure associated entities also carry #[Shared].
 *
 * ## PK preservation (CR-01)
 *
 * On insert, if the entity uses a post-insert id generator (IDENTITY), the generator type
 * is temporarily overridden to NONE so the copied landlord id is written verbatim into the
 * INSERT statement, preserving cross-DB key equality that the update/delete paths rely on.
 */
final class SharedEntityCopier implements SharedEntityCopierInterface
{
    private bool $syncInProgress = false;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Apply a single landlord row to the tenant EM (find-or-new + scalar copy + flush).
     *
     * Sets $syncInProgress around the flush call — set immediately before persist()+flush(),
     * always reset in a finally (even if flush throws). This allows
     * SharedEntityWriteProtectionListener to bypass the guard for copier-originated writes.
     *
     * @param array<string, mixed>|null $capturedIds pre-captured identifier for deletes —
     *                                               Doctrine zeroes entity ID fields before postFlush
     */
    public function applyRow(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        object $entity,
        string $type = 'insert',
        ?array $capturedIds = null,
    ): void {
        $class = $entity::class;
        $landlordMeta = $landlordEm->getClassMetadata($class);

        if ('delete' === $type) {
            // WR-03: deletes REQUIRE the identifier captured in onFlush — by postFlush Doctrine has
            // zeroed the entity's identifier fields, so getIdentifierValues($entity) returns [].
            // A ?? fallback would silently turn a missing-id bug into a delete no-op (stale shared
            // data left on the tenant). Fail loudly instead.
            if (null === $capturedIds || [] === $capturedIds) {
                $this->logger->error('tenancy.shared_entity_sync_missing_delete_id', [
                    'entity_class' => $class,
                ]);

                return;
            }

            $existing = $tenantEm->find($class, $capturedIds);
            if (null !== $existing) {
                $tenantEm->remove($existing);
                $this->syncInProgress = true;
                try {
                    $tenantEm->flush();
                } finally {
                    $this->syncInProgress = false;
                }
            }

            return;
        }

        // insert or update only — capture the identifier directly from the entity (still set).
        $ids = $landlordMeta->getIdentifierValues($entity);

        // find-or-new + scalar field copy
        $existing = $tenantEm->find($class, $ids);
        $tenantMeta = $tenantEm->getClassMetadata($class);

        $isInsert = null === $existing;
        $copy = $isInsert ? $tenantMeta->newInstance() : $existing;

        // Copy scalar fields only — associations are intentionally skipped (DEC-SHARE-02).
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
            // afterward, discarding the id we just copied onto $copy. Forcing the id generator to
            // NONE for this synced insert makes the copied landlord id authoritative.
            // Only flip it when the entity actually uses a post-insert generator, so natural /
            // assigned-id entities are unaffected.
            if ($tenantMeta->isIdGeneratorIdentity()
                || (isset($tenantMeta->idGenerator) && $tenantMeta->idGenerator->isPostInsertGenerator())) {
                $tenantMeta->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            }
        }

        $this->syncInProgress = true;
        try {
            $tenantEm->persist($copy);
            $tenantEm->flush();
        } finally {
            $this->syncInProgress = false;
        }
    }

    /**
     * Classify a single landlord row vs the current tenant's copy.
     *
     * Read-only: calls $tenantEm->find() only. NO persist, NO flush, NO newInstance.
     * Used by --dry-run and the live-run confirmation summary (D-03).
     *
     * @return 'insert'|'update'|'in-sync'
     */
    public function classifyRow(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        object $entity,
    ): string {
        $class = $entity::class;
        $landlordMeta = $landlordEm->getClassMetadata($class);
        $ids = $landlordMeta->getIdentifierValues($entity);

        $existing = $tenantEm->find($class, $ids);
        if (null === $existing) {
            return 'insert';
        }

        // Compare scalar fields to detect drift
        $tenantMeta = $tenantEm->getClassMetadata($class);
        foreach ($landlordMeta->getFieldNames() as $field) {
            if ($landlordMeta->getFieldValue($entity, $field)
                !== $tenantMeta->getFieldValue($existing, $field)) {
                return 'update';
            }
        }

        return 'in-sync';
    }

    /**
     * Enumerate all class-strings whose reflClass carries #[Shared] (D-07).
     *
     * Proxy-safe (WR-01): reflects ClassMetadata::$reflClass (the real mapped class),
     * never new \ReflectionClass($entity).
     *
     * @return list<class-string>
     */
    public function findSharedClasses(EntityManagerInterface $landlordEm): array
    {
        /** @var list<class-string> $sharedClasses */
        $sharedClasses = [];
        foreach ($landlordEm->getMetadataFactory()->getAllMetadata() as $metadata) {
            $refl = $metadata->reflClass;
            if (null !== $refl && [] !== $refl->getAttributes(Shared::class)) {
                $sharedClasses[] = $metadata->getName();
            }
        }

        return $sharedClasses;
    }

    /**
     * Returns true when the entity carries the #[Shared] attribute.
     *
     * WR-01: resolve the attribute against the REAL mapped class via Doctrine metadata
     * (ClassMetadata::$reflClass), NOT `new \ReflectionClass($entity)`. When $entity is a
     * classic Doctrine lazy-loading proxy (Proxies\__CG__\...), reflecting the runtime object
     * reflects the proxy subclass; PHP class attributes are not inherited, so getAttributes()
     * would return [] and a proxy-backed #[Shared] entity would be silently skipped.
     */
    public function isShared(object $entity, EntityManagerInterface $em): bool
    {
        $refl = $em->getClassMetadata($entity::class)->reflClass;

        return null !== $refl && [] !== $refl->getAttributes(Shared::class);
    }

    /**
     * Whether the copier is currently writing to a tenant EM (re-entrancy flag).
     *
     * SharedEntityWriteProtectionListener calls this to bypass the write-protection guard
     * when the write originates from this copier's own sync operation (not user code).
     * The flag is true only inside the flush() boundary within applyRow().
     */
    public function isSyncInProgress(): bool
    {
        return $this->syncInProgress;
    }
}
