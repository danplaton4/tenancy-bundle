<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Doctrine DBAL driver-middleware that injects TenantAwareDriver into the middleware chain
 * for the tenant connection. Registered via the `doctrine.middleware` tag with
 * `connection: tenant` attribute — scoped per-connection to prevent the landlord connection
 * from receiving tenant param merges.
 *
 * Replaces the v0.1 `wrapperClass` + `ReflectionProperty` approach. DBAL 4 resolves the
 * `Driver` at `DriverManager::getConnection()` construction time and stores it immutably
 * on the `Connection`; the only architecturally correct way to rotate the underlying socket
 * per-tenant is to wrap the driver itself, which this middleware does via `wrap()`.
 */
final class TenantDriverMiddleware implements Middleware
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TenantAwareDriver($driver, $this->tenantContext);
    }
}
