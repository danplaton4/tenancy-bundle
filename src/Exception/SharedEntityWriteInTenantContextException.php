<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

/**
 * Thrown when a #[Shared] entity write (insert / update / delete) is attempted
 * while a tenant context is active.
 *
 * Shared entities are landlord-side master records. Write to the landlord
 * EntityManager instead; SharedEntitySyncSubscriber will propagate the change
 * to all tenant EntityManagers automatically (D-01 best-effort fan-out).
 *
 * Extends \LogicException (not \RuntimeException) so Symfony Messenger's
 * default retry strategy does NOT re-queue the worker: a tenant-side write to
 * a shared entity is a programmer/operator error (WR-01 no-retry invariant),
 * not a transient fault. Retrying would produce the same exception.
 *
 * @see src/Subscriber/SharedEntityWriteProtectionListener.php — the onFlush
 *      guard that throws this exception when a scheduled insert/update/delete
 *      is detected for a #[Shared] entity in tenant context.
 */
final class SharedEntityWriteInTenantContextException extends \LogicException
{
    public static function forEntity(string $entityClass, string $tenantSlug): self
    {
        return new self(sprintf(
            'tenancy: cannot write entity "%s" in tenant context "%s" — shared entities are read-only on the tenant side. Write to the landlord EntityManager instead; SharedEntitySyncSubscriber will propagate the change to all tenants.',
            $entityClass,
            $tenantSlug
        ));
    }
}
