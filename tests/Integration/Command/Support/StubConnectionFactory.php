<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command\Support;

use Doctrine\DBAL\DriverManager;
use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\DBAL\TenantConnectionInterface;

/**
 * Factory that creates a minimal in-memory SQLite TenantConnection for use in
 * command DI wiring tests.
 *
 * We need a real TenantConnection instance because DatabaseSwitchBootstrapper
 * requires TenantConnectionInterface. Using wrapperClass ensures DriverManager
 * instantiates TenantConnection.
 */
final class StubConnectionFactory
{
    public static function create(): TenantConnectionInterface
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => TenantConnection::class,
        ]);
    }
}
