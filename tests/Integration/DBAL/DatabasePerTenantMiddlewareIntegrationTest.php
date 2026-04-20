<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\Tests\Integration\Support\DoctrineTestKernel;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct;

/**
 * Load-bearing regression test for FIX-03: proves tenant data isolation via real
 * connect()/INSERT/SELECT roundtrip against two distinct SQLite files.
 *
 * Mechanism under test:
 *   - setTenant() stores the active tenant in TenantContext.
 *   - $conn->close() nulls the internal driver connection.
 *   - Next query → DBAL's lazy-connect path → TenantDriverMiddleware wraps →
 *     TenantAwareDriver::connect() merges tenant->getConnectionConfig() over params →
 *     wrapped driver connects to the correct SQLite file.
 *
 * Data-level assertions are the gate:
 *   - Rows inserted as Tenant A are NOT visible as Tenant B.
 *   - Switching back to Tenant A re-exposes Tenant A's rows.
 *   - The landlord connection (scoped out of the middleware tag) is never contaminated.
 */
final class DatabasePerTenantMiddlewareIntegrationTest extends TestCase
{
    private static ?DoctrineTestKernel $kernel = null;
    private static string $pathA;
    private static string $pathB;
    private static string $landlordPath;

    public static function setUpBeforeClass(): void
    {
        self::$pathA = sys_get_temp_dir().'/tenancy_middleware_test_tenant_a.db';
        self::$pathB = sys_get_temp_dir().'/tenancy_middleware_test_tenant_b.db';
        self::$landlordPath = sys_get_temp_dir().'/tenancy_test_landlord.db';
        foreach ([self::$pathA, self::$pathB, self::$landlordPath, sys_get_temp_dir().'/tenancy_test_placeholder.db'] as $p) {
            @unlink($p);
        }

        self::$kernel = new DoctrineTestKernel();
        self::$kernel->boot();

        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var Connection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        // Pre-create schemas on both tenant files.
        foreach ([self::$pathA, self::$pathB] as $path) {
            $tenant = (new Tenant('pre-'.basename($path), basename($path)))
                ->setConnectionConfig(['path' => $path]);
            $ctx->setTenant($tenant);
            $conn->close();

            $em = $registry->resetManager('tenant');
            $schemaTool = new SchemaTool($em);
            $schemaTool->createSchema([$em->getClassMetadata(TestProduct::class)]);
        }

        // Pre-create landlord schema (tenancy_tenants table + Tenant entity)
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        $landlordSchemaTool = new SchemaTool($landlordEm);
        $landlordSchemaTool->createSchema($landlordEm->getMetadataFactory()->getAllMetadata());

        // Clear context so tests start from a clean slate.
        $ctx->clear();
        $conn->close();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        foreach ([self::$pathA, self::$pathB, self::$landlordPath, sys_get_temp_dir().'/tenancy_test_placeholder.db'] as $p) {
            @unlink($p);
        }
    }

    public function testRealTwoTenantSqliteFileRoundtripIsolatesData(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var Connection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        // --- Tenant A: insert one row ---
        $tenantA = (new Tenant('a', 'A'))->setConnectionConfig(['path' => self::$pathA]);
        $ctx->setTenant($tenantA);
        $conn->close();

        $emA = $registry->resetManager('tenant');
        $emA->persist(new TestProduct('only-in-A'));
        $emA->flush();

        $this->assertFileExists(self::$pathA);
        $this->assertGreaterThan(0, filesize(self::$pathA), 'Tenant A DB file is on disk and non-empty');

        // --- Tenant B: should see zero rows (different SQLite file) ---
        $tenantB = (new Tenant('b', 'B'))->setConnectionConfig(['path' => self::$pathB]);
        $ctx->setTenant($tenantB);
        $conn->close();

        $countB = (int) $conn->fetchOne('SELECT COUNT(*) FROM test_products');
        $this->assertSame(0, $countB, 'Tenant B must see no rows — data is isolated from Tenant A');

        // --- Switch back to Tenant A: row must still be there ---
        $ctx->setTenant($tenantA);
        $conn->close();
        $countA = (int) $conn->fetchOne('SELECT COUNT(*) FROM test_products');
        $this->assertSame(1, $countA, 'Tenant A row is still present after switching back');
    }

    public function testLandlordConnectionUnaffectedByTenantSwitches(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var Connection $tenantConn */
        $tenantConn = $container->get('doctrine.dbal.tenant_connection');
        /** @var Connection $landlordConn */
        $landlordConn = $container->get('doctrine.dbal.landlord_connection');

        // Switch to Tenant A on the tenant connection — landlord params must remain untouched.
        $tenantA = (new Tenant('a', 'A'))->setConnectionConfig(['path' => self::$pathA]);
        $ctx->setTenant($tenantA);
        $tenantConn->close();

        $landlordParams = $landlordConn->getParams();
        $this->assertSame(
            self::$landlordPath,
            $landlordParams['path'] ?? null,
            "Landlord 'path' must remain the landlord DB even with a tenant active — "
            ."middleware tag scoping ['connection' => 'tenant'] prevents landlord contamination.",
        );
    }

    public function testLandlordEntityManagerQueriesLandlordDbRegardlessOfTenant(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var Connection $tenantConn */
        $tenantConn = $container->get('doctrine.dbal.tenant_connection');
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');

        // Persist a landlord-side Tenant entity.
        $slug = 'landlord-probe-'.bin2hex(random_bytes(3));
        $entity = new Tenant($slug, 'Landlord Probe');
        $landlordEm->persist($entity);
        $landlordEm->flush();

        // Now rotate tenant connection between A and B.
        $tenantA = (new Tenant('a', 'A'))->setConnectionConfig(['path' => self::$pathA]);
        $tenantB = (new Tenant('b', 'B'))->setConnectionConfig(['path' => self::$pathB]);
        $ctx->setTenant($tenantA);
        $tenantConn->close();
        $ctx->setTenant($tenantB);
        $tenantConn->close();

        // Landlord EM must still find the probe — its connection never saw tenant params.
        $landlordEm->clear();
        $found = $landlordEm->find(Tenant::class, $slug);
        $this->assertNotNull($found, 'Landlord EM must resolve landlord-side entity regardless of tenant switches.');
        $this->assertSame('Landlord Probe', $found->getName());
    }
}
