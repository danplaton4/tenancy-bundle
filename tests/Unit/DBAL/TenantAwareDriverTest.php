<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\DBAL\TenantAwareDriver;
use Tenancy\Bundle\DBAL\TenantDriverMiddleware;
use Tenancy\Bundle\Entity\Tenant;

final class TenantAwareDriverTest extends TestCase
{
    public function testMiddlewareWrapsGivenDriverIntoTenantAwareDriver(): void
    {
        $wrapped = $this->createMock(Driver::class);
        $ctx = new TenantContext();

        $middleware = new TenantDriverMiddleware($ctx);
        $result = $middleware->wrap($wrapped);

        $this->assertInstanceOf(TenantAwareDriver::class, $result);
    }

    public function testConnectWithoutTenantPassesParamsThrough(): void
    {
        $driverConn = $this->createMock(DriverConnection::class);
        $wrapped = $this->createMock(Driver::class);
        $wrapped->expects($this->once())
            ->method('connect')
            ->with(['driver' => 'pdo_sqlite', 'path' => 'landlord.db'])
            ->willReturn($driverConn);

        $ctx = new TenantContext();
        $driver = new TenantAwareDriver($wrapped, $ctx);

        $result = $driver->connect(['driver' => 'pdo_sqlite', 'path' => 'landlord.db']);
        $this->assertSame($driverConn, $result);
    }

    public function testConnectWithTenantMergesTenantParamsOverLandlordParams(): void
    {
        $driverConn = $this->createMock(DriverConnection::class);
        $wrapped = $this->createMock(Driver::class);
        $wrapped->expects($this->once())
            ->method('connect')
            ->with($this->callback(function (array $params): bool {
                return 'pdo_sqlite' === $params['driver']
                    && 'tenant_a.db' === $params['path'];
            }))
            ->willReturn($driverConn);

        $ctx = new TenantContext();
        $tenant = (new Tenant('acme', 'Acme'))->setConnectionConfig(['path' => 'tenant_a.db']);
        $ctx->setTenant($tenant);

        $driver = new TenantAwareDriver($wrapped, $ctx);
        $driver->connect(['driver' => 'pdo_sqlite', 'path' => 'landlord.db']);
    }

    public function testConnectWithTenantPreservesLandlordDriverKey(): void
    {
        $driverConn = $this->createMock(DriverConnection::class);
        $wrapped = $this->createMock(Driver::class);
        $wrapped->expects($this->once())
            ->method('connect')
            ->with($this->callback(fn (array $p): bool => 'pdo_sqlite' === $p['driver']))
            ->willReturn($driverConn);

        $ctx = new TenantContext();
        // Tenant does NOT specify 'driver' — landlord placeholder key survives.
        $tenant = (new Tenant('x', 'X'))->setConnectionConfig(['path' => 'x.db']);
        $ctx->setTenant($tenant);

        $driver = new TenantAwareDriver($wrapped, $ctx);
        $driver->connect(['driver' => 'pdo_sqlite', 'path' => 'landlord.db']);
    }

    public function testInheritedGetDatabasePlatformAndExceptionConverterDelegate(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $converter = $this->createMock(ExceptionConverter::class);
        $versionProvider = $this->createMock(ServerVersionProvider::class);

        $wrapped = $this->createMock(Driver::class);
        $wrapped->method('getDatabasePlatform')->with($versionProvider)->willReturn($platform);
        $wrapped->method('getExceptionConverter')->willReturn($converter);

        $driver = new TenantAwareDriver($wrapped, new TenantContext());

        $this->assertSame($platform, $driver->getDatabasePlatform($versionProvider));
        $this->assertSame($converter, $driver->getExceptionConverter());
    }
}
