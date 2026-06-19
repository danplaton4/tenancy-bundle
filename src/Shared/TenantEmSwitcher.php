<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

/**
 * Lightweight tenant EM switch/restore service for per-change fan-out.
 *
 * Owns the two operations that were previously duplicated across
 * SharedEntitySyncSubscriber and SharedEntityChangedMessageHandler (W-02).
 *
 * ## This is the lightweight switch path
 *
 * switchTo() does: setTenant() → tenant DBAL close() → resetManager('tenant').
 * restore() does: set-or-clear context → tenant DBAL close() → resetManager('tenant').
 *
 * This is intentionally NOT the full bootstrapper-chain path. SharedEntityResyncCommand
 * uses setTenant() + bootstrapperChain->boot() (fires TenantBootstrapped + all
 * bootstrappers) for CLI backfill semantics. Firing every bootstrapper on each
 * per-change / per-message event would cause perf + side-effect issues. See W-03
 * in the Phase 30 audit and the back-reference note in SharedEntityResyncCommand.
 *
 * @see SharedEntityResyncCommand::resyncForTenant() for the heavier full-boot path
 */
final class TenantEmSwitcher implements TenantEmSwitcherInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function switchTo(TenantInterface $tenant): EntityManagerInterface
    {
        $this->tenantContext->setTenant($tenant);

        // Force the tenant DBAL connection to reconnect via TenantAwareDriver::connect()
        // so it picks up the new tenant's connection params. Without close(), the
        // previously-open socket stays connected to the prior tenant's DB (DBAL only
        // calls connect() when the internal connection handle is null).
        $tenantConn = $this->registry->getConnection('tenant');
        if ($tenantConn instanceof Connection) {
            $tenantConn->close();
        }

        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $this->registry->resetManager('tenant');

        return $tenantEm;
    }

    public function restore(?TenantInterface $previousTenant): void
    {
        if (null !== $previousTenant) {
            $this->tenantContext->setTenant($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        $tenantConn = $this->registry->getConnection('tenant');
        if ($tenantConn instanceof Connection) {
            $tenantConn->close();
        }
        $this->registry->resetManager('tenant');
    }
}
