<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Factory that creates a minimal in-memory SQLite DBAL Connection for use in
 * command DI wiring tests.
 *
 * We need a real Connection instance (not a mock) because DBAL's DriverManager
 * validates connection parameters at construction time and the container compiles
 * a Definition for this service rather than using a factory callable.
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
