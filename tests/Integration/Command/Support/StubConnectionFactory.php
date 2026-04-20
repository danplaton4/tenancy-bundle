<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Factory that creates a minimal in-memory SQLite DBAL Connection for use in
 * command DI wiring tests.
 *
 * Since v0.2, DatabaseSwitchBootstrapper accepts a plain Doctrine\DBAL\Connection
 * (the per-tenant middleware rotates the underlying socket, not the Connection object).
 * This factory returns a stock Connection with no wrapperClass — the middleware is
 * registered separately via TenancyBundle::loadExtension() in the test kernel.
 */
final class StubConnectionFactory
{
    public static function create(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }
}
