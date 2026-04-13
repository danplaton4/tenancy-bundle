<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DBAL;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;

/**
 * DBAL 4 wrapperClass subclass that switches database connections at runtime
 * by mutating the private $params property via reflection.
 *
 * Registered as wrapperClass in Doctrine configuration so DBAL's DriverManager
 * instantiates this class instead of the base Connection.
 *
 * @see \Tenancy\Bundle\Bootstrapper\DatabaseSwitchBootstrapper
 */
final class TenantConnection extends Connection implements TenantConnectionInterface
{
    /** @var array<string, mixed> */
    private readonly array $originalParams;

    private readonly \ReflectionProperty $paramsReflector;

    public function __construct(
        #[\SensitiveParameter]
        array $params,
        Driver $driver,
        ?Configuration $config = null,
    ) {
        parent::__construct($params, $driver, $config);
        $this->originalParams = $params;
        $this->paramsReflector = new \ReflectionProperty(Connection::class, 'params');
    }

    /**
     * Switch the underlying DBAL connection to the given tenant database configuration.
     * Merges tenant-specific keys over the original (landlord) params, then closes
     * the current connection so the next query reconnects to the tenant's database.
     *
     * @param array<string, mixed> $tenantConnectionConfig
     */
    public function switchTenant(array $tenantConnectionConfig): void
    {
        $merged = array_merge($this->originalParams, $tenantConnectionConfig);
        $this->paramsReflector->setValue($this, $merged);
        $this->close();
    }

    /**
     * Reset the connection back to the original (landlord) database configuration.
     * Closes the current connection so the next query reconnects to the landlord database.
     */
    public function reset(): void
    {
        $this->paramsReflector->setValue($this, $this->originalParams);
        $this->close();
    }
}
