<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Driver;

use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;

/**
 * Marker interface for tenant isolation drivers (e.g. database-per-tenant, shared-db).
 * Drivers are bootstrappers that perform connection-level isolation on boot/clear.
 */
interface TenantDriverInterface extends TenantBootstrapperInterface
{
}
