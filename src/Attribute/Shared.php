<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Attribute;

/**
 * Marks a Doctrine entity as a landlord-side master record that is synced
 * read-only into each active tenant's EntityManager via SharedEntitySyncSubscriber.
 *
 * When a `#[Shared]` entity is written on the landlord EM, the sync subscriber
 * fans the change (insert / update / delete) out to every tenant EM returned by
 * TenantProviderInterface::findAll(). Fan-out is best-effort (D-01): per-tenant
 * failures are logged and never abort the landlord transaction.
 *
 * Tenant-side copies are write-protected: any attempt to persist, update, or
 * delete a `#[Shared]` entity in an active tenant context throws
 * SharedEntityWriteInTenantContextException (D-02). Write to the landlord EM
 * instead; the sync subscriber propagates the change automatically.
 *
 * MUST NOT be combined with #[TenantAware] on the same class — a shared entity
 * is a landlord-side master, while a TenantAware entity is tenant-scoped. The
 * SharedEntityMutualExclusionPass enforces this constraint at container compile
 * time (D-04).
 *
 * Under the `shared_db` driver this attribute is a documented no-op (D-03):
 * there are no per-tenant EMs to fan out to — the entity lives once in the
 * single shared database.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Shared
{
}
