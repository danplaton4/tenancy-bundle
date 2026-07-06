<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Doctrine\DBAL\Connection;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthCheckBootstrapperInterface;
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
final class DatabaseSwitchBootstrapper implements TenantDriverInterface, HealthCheckBootstrapperInterface
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
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
    }

    /**
     * Performs a read-only DB connectivity probe (D-03).
     *
     * Reuses the same close()+lazy-reconnect mechanism that boot() uses.
     * After close(), TenantDriverMiddleware reads the current TenantContext on
     * the next query and opens a fresh connection to the correct tenant DB.
     * This class holds no tenant-specific state — the probe is stateless (T-33-STATE).
     */
    public function check(TenantInterface $tenant): BootstrapperHealthResult
    {
        try {
            $this->connection->close();
            $this->connection->executeQuery('SELECT 1');

            return BootstrapperHealthResult::pass(static::class);
        } catch (\Throwable $e) {
            return BootstrapperHealthResult::fail(static::class, $e->getMessage(), $e);
        }
    }
}
