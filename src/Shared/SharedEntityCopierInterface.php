<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Contract for the shared-entity upsert/classify/enumeration service.
 *
 * Extracted alongside the final SharedEntityCopier so PHPUnit can create
 * mock objects for command unit tests (PHPUnit 11 ClassIsFinalException
 * prevents mocking final classes — same pattern as TenantConnectionInterface).
 *
 * @see SharedEntityCopier
 */
interface SharedEntityCopierInterface
{
    /**
     * Apply a single landlord row to the tenant EM (find-or-new + scalar copy + flush).
     *
     * @param array<string, mixed>|null $capturedIds pre-captured identifier for deletes
     */
    public function applyRow(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        object $entity,
        string $type = 'insert',
        ?array $capturedIds = null,
    ): void;

    /**
     * Classify a single landlord row vs the current tenant's copy.
     *
     * Read-only: no persist, no flush.
     *
     * @return 'insert'|'update'|'in-sync'
     */
    public function classifyRow(
        EntityManagerInterface $landlordEm,
        EntityManagerInterface $tenantEm,
        object $entity,
    ): string;

    /**
     * Enumerate all class-strings whose reflClass carries #[Shared] (D-07).
     *
     * @return list<class-string>
     */
    public function findSharedClasses(EntityManagerInterface $landlordEm): array;

    /**
     * Returns true when the entity carries the #[Shared] attribute.
     */
    public function isShared(object $entity, EntityManagerInterface $em): bool;

    /**
     * Delete the tenant-side copy of a shared entity by its captured identifier.
     *
     * Idempotent: if no tenant-side copy exists for the given $capturedIds, the method
     * returns without error (no-op). Sets the syncInProgress flag around the flush so
     * SharedEntityWriteProtectionListener bypasses the guard for this copier-originated write,
     * identical to the delete sub-path in applyRow().
     *
     * Use this instead of applyRow() when no live entity object is available (e.g. the
     * handler re-fetch returns null or the delete path in the async handler — OQ-1 resolution).
     *
     * @param class-string         $class       FQCN of the #[Shared] entity
     * @param array<string, mixed> $capturedIds Pre-captured primary-key values
     */
    public function deleteRow(EntityManagerInterface $tenantEm, string $class, array $capturedIds): void;

    /**
     * Whether the copier is currently writing to a tenant EM (re-entrancy flag).
     */
    public function isSyncInProgress(): bool;
}
