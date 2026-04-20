<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Tenancy\Bundle\Context\TenantContext;

/**
 * AbstractDriverMiddleware subclass invoked on every Connection::connect() reconnect.
 * Reads the active tenant from TenantContext and merges its getConnectionConfig() over
 * the frozen landlord params before delegating to the wrapped driver.
 *
 * `$this->params` on the outer DBAL Connection is frozen at construction time; the only
 * place tenant-specific params can be merged is at connect() time, which is exactly where
 * this middleware runs. `Connection::close()` nulls the internal driver-connection handle;
 * the next query re-enters this `connect()` with the fresh TenantContext.
 *
 * Important: `url` keys in tenant config have no effect here. DriverManager parses `url`
 * into discrete keys BEFORE middlewares wrap the driver. Tenants should expose discrete
 * params (`dbname`, `host`, `port`, `user`, `password`, `path`, ...) — never `url` — in
 * `getConnectionConfig()`. See CHANGELOG 0.2.0 and UPGRADE.md.
 *
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class TenantAwareDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $wrappedDriver,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct($wrappedDriver);
    }

    /**
     * @param Params $params
     */
    public function connect(array $params): DriverConnection
    {
        $tenant = $this->tenantContext->getTenant();

        if (null !== $tenant) {
            $tenantConfig = $tenant->getConnectionConfig();
            if (array_key_exists('url', $tenantConfig)) {
                throw new \LogicException(sprintf('Tenant "%s" returned "url" in getConnectionConfig(); use discrete keys (driver, host, dbname, ...) — url is parsed before middlewares run and has no effect.', $tenant->getSlug()));
            }
            // Tenant keys win; driver stays consistent (tenant config MUST match landlord driver family).
            /** @var Params $params */
            $params = array_merge($params, $tenantConfig);
        }

        return parent::connect($params);
    }
}
