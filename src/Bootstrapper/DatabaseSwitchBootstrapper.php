<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\DBAL\TenantConnectionInterface;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Bootstrapper that switches the DBAL connection to the active tenant's database on boot,
 * and resets it to the landlord database on clear.
 *
 * Delegates to TenantConnectionInterface::switchTenant() and ::reset() so that
 * the actual connection-switching logic is encapsulated in the connection wrapper.
 */
final class DatabaseSwitchBootstrapper implements TenantDriverInterface
{
    public function __construct(private readonly TenantConnectionInterface $tenantConnection)
    {
    }

    public function boot(TenantInterface $tenant): void
    {
        $this->tenantConnection->switchTenant($tenant->getConnectionConfig());
    }

    public function clear(): void
    {
        $this->tenantConnection->reset();
    }
}
