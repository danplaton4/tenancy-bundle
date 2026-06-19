<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Contract for the tenant entity-manager switch/restore service.
 *
 * Extracted alongside the final TenantEmSwitcher so PHPUnit can create
 * mock objects for unit tests (PHPUnit 11 ClassIsFinalException
 * prevents mocking final classes — same pattern as TenantConnectionInterface).
 *
 * @see TenantEmSwitcher
 */
interface TenantEmSwitcherInterface
{
    /**
     * Switch TenantContext to $tenant, close the tenant DBAL connection, and return
     * a fresh tenant EntityManager bound to that tenant's database.
     *
     * Lightweight per-change / per-message switch path. Contrast with
     * SharedEntityResyncCommand which uses setTenant() + bootstrapperChain->boot()
     * (full bootstrapper chain — appropriate for CLI backfill, not per-event fan-out).
     *
     * CR-01: saves and re-instates the request-scoped tenant across the fan-out loop.
     * CR-02: closes the DBAL connection so the next query reconnects under the new context.
     */
    public function switchTo(TenantInterface $tenant): EntityManagerInterface;

    /**
     * Restore the tenant context that was active before the fan-out and drop the
     * tenant connection handle so the next query reconnects under the restored context.
     *
     * CR-01: re-instates the request-scoped tenant (or clears if none was active).
     * CR-02: closing the connection prevents later queries in the same request from
     * silently hitting the last-switched tenant's DB.
     */
    public function restore(?TenantInterface $previousTenant): void;
}
