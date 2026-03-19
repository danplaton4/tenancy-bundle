<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\DatabaseSwitchBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\DBAL\TenantConnectionInterface;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\TenantInterface;

final class DatabaseSwitchBootstrapperTest extends TestCase
{
    private TenantConnectionInterface $connection;
    private TenantInterface $tenant;
    private DatabaseSwitchBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        $this->connection  = $this->createMock(TenantConnectionInterface::class);
        $this->tenant      = $this->createMock(TenantInterface::class);
        $this->bootstrapper = new DatabaseSwitchBootstrapper($this->connection);
    }

    public function testBootCallsSwitchTenantWithConnectionConfig(): void
    {
        $config = ['dbname' => 'tenant_acme', 'host' => 'db.internal'];

        $this->tenant
            ->expects($this->once())
            ->method('getConnectionConfig')
            ->willReturn($config);

        $this->connection
            ->expects($this->once())
            ->method('switchTenant')
            ->with($config);

        $this->bootstrapper->boot($this->tenant);
    }

    public function testClearCallsReset(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('reset');

        $this->bootstrapper->clear();
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
