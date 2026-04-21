<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\DatabaseSwitchBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\TenantInterface;

final class DatabaseSwitchBootstrapperTest extends TestCase
{
    private Connection&MockObject $connection;
    private DatabaseSwitchBootstrapper $bootstrapper;

    public static function setUpBeforeClass(): void
    {
        // DatabaseSwitchBootstrapper is only wired into the DI container when
        // tenancy.database.enabled=true, which itself requires doctrine/dbal.
        // The no-doctrine CI job removes DBAL from vendor/; this guard lets
        // the test coexist with that job without pulling DBAL back in.
        if (!class_exists(Connection::class)) {
            self::markTestSkipped('Doctrine DBAL is not installed; DatabaseSwitchBootstrapper is unreachable without it.');
        }
    }

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->bootstrapper = new DatabaseSwitchBootstrapper($this->connection);
    }

    public function testBootClosesTheConnection(): void
    {
        $this->connection->expects($this->once())->method('close');

        $tenant = new Tenant('acme', 'Acme');
        $this->bootstrapper->boot($tenant);
    }

    public function testClearClosesTheConnectionWhenConnected(): void
    {
        $this->connection->method('isConnected')->willReturn(true);
        $this->connection->expects($this->once())->method('close');
        $this->bootstrapper->clear();
    }

    public function testClearSkipsCloseWhenNotConnected(): void
    {
        $this->connection->method('isConnected')->willReturn(false);
        $this->connection->expects($this->never())->method('close');
        $this->bootstrapper->clear();
    }

    public function testBootDoesNotReadTenantConnectionConfig(): void
    {
        // The middleware — not the bootstrapper — is responsible for reading
        // TenantInterface::getConnectionConfig(). The bootstrapper's only job is
        // to force a reconnect by closing the current driver-level connection.
        $this->connection->expects($this->once())->method('close');

        $tenant = $this->createMock(TenantInterface::class);
        $tenant->expects($this->never())->method('getConnectionConfig');

        $this->bootstrapper->boot($tenant);
    }

    public function testImplementsTenantBootstrapperInterface(): void
    {
        $this->assertInstanceOf(TenantBootstrapperInterface::class, $this->bootstrapper);
    }

    public function testImplementsTenantDriverInterface(): void
    {
        $this->assertInstanceOf(TenantDriverInterface::class, $this->bootstrapper);
    }
}
