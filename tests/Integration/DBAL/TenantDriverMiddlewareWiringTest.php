<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\DBAL;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\DBAL\TenantDriverMiddleware;
use Tenancy\Bundle\Tests\Integration\Support\DoctrineTestKernel;

/**
 * Proves the DBAL driver-middleware is registered with the correct tag attributes
 * in database_per_tenant mode — scoped to the tenant connection only.
 *
 * Companion regression: DatabasePerTenantMiddlewareIntegrationTest does the end-to-end
 * SQLite roundtrip. This test inspects the compiled container state to guarantee the
 * tag scoping ['connection' => 'tenant'] is honored at DI compile time.
 */
final class TenantDriverMiddlewareWiringTest extends TestCase
{
    private static ?DoctrineTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        @unlink(sys_get_temp_dir().'/tenancy_test_landlord.db');
        self::$kernel = new DoctrineTestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        @unlink(sys_get_temp_dir().'/tenancy_test_landlord.db');
    }

    public function testTenantConnectionChildMiddlewareIsRegistered(): void
    {
        // DoctrineBundle's MiddlewaresPass generates a child definition
        // <middleware_id>.<connection_name> per target connection. For a
        // `connection: tenant` tag the child is `tenancy.dbal.tenant_driver_middleware.tenant`.
        $container = self::$kernel->getContainer();
        $this->assertTrue(
            $container->has('tenancy.dbal.tenant_driver_middleware.tenant'),
            'MiddlewaresPass did not generate the tenant-scoped child definition — check the '
            ."'doctrine.middleware' tag with ['connection' => 'tenant'] attribute on "
            .'tenancy.dbal.tenant_driver_middleware.',
        );
    }

    public function testTenantMiddlewareChildResolvesToTenantDriverMiddleware(): void
    {
        $container = self::$kernel->getContainer();
        $svc = $container->get('tenancy.dbal.tenant_driver_middleware.tenant');
        $this->assertInstanceOf(TenantDriverMiddleware::class, $svc);
    }

    public function testLandlordConnectionHasNoTenantDriverMiddlewareChild(): void
    {
        // The connection tag scopes the middleware to 'tenant' only. If someone drops the
        // attribute or adds a second tag, DoctrineBundle would generate a
        // tenancy.dbal.tenant_driver_middleware.landlord child — asserting its absence is
        // the definitive regression guard against a landlord leak.
        $container = self::$kernel->getContainer();
        $this->assertFalse(
            $container->has('tenancy.dbal.tenant_driver_middleware.landlord'),
            'Landlord child middleware MUST NOT exist — doctrine.middleware tag scoping to '
            ."['connection' => 'tenant'] was lost; tenant getConnectionConfig() would be merged "
            .'into landlord queries.',
        );
    }

    public function testTenantConnectionConfigurationHasMiddlewareInChain(): void
    {
        // Verify the middleware actually made it into the tenant connection's Configuration —
        // a private Configuration service is inlined after compile, but it is reachable via
        // Connection::getConfiguration() on the public tenant_connection.
        $container = self::$kernel->getContainer();
        /** @var Connection $tenantConn */
        $tenantConn = $container->get('doctrine.dbal.tenant_connection');

        $middlewares = $tenantConn->getConfiguration()->getMiddlewares();

        $found = false;
        foreach ($middlewares as $middleware) {
            if ($middleware instanceof TenantDriverMiddleware) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'Tenant connection Configuration must include TenantDriverMiddleware in its chain.',
        );
    }

    public function testLandlordConnectionConfigurationDoesNotHaveTenantMiddleware(): void
    {
        $container = self::$kernel->getContainer();
        /** @var Connection $landlordConn */
        $landlordConn = $container->get('doctrine.dbal.landlord_connection');

        foreach ($landlordConn->getConfiguration()->getMiddlewares() as $middleware) {
            $this->assertNotInstanceOf(
                TenantDriverMiddleware::class,
                $middleware,
                'Landlord Configuration must not contain TenantDriverMiddleware.',
            );
        }

        $this->addToAssertionCount(1); // at least one assertion even if landlord chain is empty
    }
}
