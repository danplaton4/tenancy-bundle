<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DBAL;

/**
 * Contract for a DBAL connection that can switch between tenant databases at runtime.
 * Implemented by TenantConnection; extracted as an interface so that
 * DatabaseSwitchBootstrapper can be unit-tested without instantiating the full
 * Doctrine Connection hierarchy.
 */
interface TenantConnectionInterface
{
    /**
     * Switch the underlying DBAL connection to the given tenant database configuration.
     *
     * @param array<string, mixed> $config
     */
    public function switchTenant(array $config): void;

    /**
     * Reset the connection back to the default (landlord) database configuration.
     */
    public function reset(): void;
}
