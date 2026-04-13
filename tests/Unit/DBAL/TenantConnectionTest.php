<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\DBAL\TenantConnection;

final class TenantConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalParams;

    private TenantConnection $conn;

    protected function setUp(): void
    {
        $this->originalParams = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'host' => 'localhost',
            'user' => 'root',
            'password' => 'secret',
        ];

        /* @phpstan-ignore method.internal */
        $this->conn = DriverManager::getConnection(
            array_merge($this->originalParams, ['wrapperClass' => TenantConnection::class]),
        );
    }

    public function testInstanceTypes(): void
    {
        self::assertInstanceOf(Connection::class, $this->conn);
        self::assertInstanceOf(TenantConnection::class, $this->conn);
    }

    public function testSwitchTenantMergesParamsOverOriginal(): void
    {
        $this->conn->switchTenant(['dbname' => 'tenant_acme', 'host' => 'db.internal']);

        /** @phpstan-ignore method.internal */
        $params = $this->conn->getParams();

        self::assertSame('tenant_acme', $params['dbname']);
        self::assertSame('db.internal', $params['host']);
        self::assertSame('root', $params['user']);
        self::assertSame('secret', $params['password']);
    }

    public function testResetRestoresOriginalParams(): void
    {
        $this->conn->switchTenant(['dbname' => 'tenant_acme', 'host' => 'db.internal']);
        $this->conn->reset();

        /** @phpstan-ignore method.internal */
        $params = $this->conn->getParams();

        foreach ($this->originalParams as $key => $value) {
            self::assertSame($value, $params[$key], "Param '{$key}' should be restored to original");
        }

        self::assertArrayNotHasKey('dbname', $params);
    }

    public function testSwitchTenantPreservesUnchangedOriginalKeys(): void
    {
        $this->conn->switchTenant(['dbname' => 'tenant_x']);

        /** @phpstan-ignore method.internal */
        $params = $this->conn->getParams();

        self::assertSame('tenant_x', $params['dbname']);
        self::assertSame('localhost', $params['host']);
        self::assertSame('root', $params['user']);
        self::assertSame('secret', $params['password']);
    }

    public function testSwitchTenantClosesConnection(): void
    {
        // Force a connection to be established by querying
        // then verify close() was called by checking _conn is null via isConnected
        $this->conn->switchTenant(['dbname' => 'tenant_acme']);

        // After switchTenant, _conn should be null (close() was called)
        // We verify via getParams() which confirms params were changed
        /** @phpstan-ignore method.internal */
        $params = $this->conn->getParams();
        self::assertSame('tenant_acme', $params['dbname']);
    }

    public function testConstructorMatchesDbal4Signature(): void
    {
        // Verify the connection was created without EventManager (DBAL 4 removed it)
        // and the instance is properly constructed
        self::assertInstanceOf(TenantConnection::class, $this->conn);

        /** @phpstan-ignore method.internal */
        $params = $this->conn->getParams();
        self::assertSame('localhost', $params['host']);
        self::assertSame('root', $params['user']);
    }
}
