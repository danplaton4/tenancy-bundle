<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Doctrine\DBAL\Connection;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Forces the tenant DBAL connection to reconnect on every tenant switch.
 *
 * In database_per_tenant mode, TenantDriverMiddleware wraps the tenant connection's driver
 * and merges the active tenant's getConnectionConfig() at Connection::connect() time.
 * Calling $connection->close() nulls the internal driver-connection reference; the next
 * query triggers a lazy re-connect that re-enters the middleware with fresh TenantContext.
 *
 * This class holds no tenant-specific state. The socket rotation is entirely driven by
 * the middleware chain + DBAL's lazy-connect path.
 *
 * @see \Tenancy\Bundle\DBAL\TenantDriverMiddleware
 * @see \Tenancy\Bundle\DBAL\TenantAwareDriver
 */
final class DatabaseSwitchBootstrapper implements TenantDriverInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function boot(TenantInterface $tenant): void
    {
        $this->connection->close();
    }

    public function clear(): void
    {
        $this->connection->close();
    }
}
