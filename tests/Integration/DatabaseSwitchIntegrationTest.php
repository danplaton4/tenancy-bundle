<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\Tests\Integration\Support\DoctrineTestKernel;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct;

/**
 * Integration tests proving cross-tenant query isolation.
 *
 * Verifies:
 *   - After switchTenant(tenantA), queries through the tenant EM hit Tenant A's database.
 *   - After switchTenant(tenantB), queries through the tenant EM hit Tenant B's database — not A's.
 *   - The landlord EM always reads from the central DB and is unaffected by tenant switches.
 *   - Switching back to the same tenant after reset reconnects to the same DB file.
 */
final class DatabaseSwitchIntegrationTest extends TestCase
{
    private static DoctrineTestKernel $kernel;
    private static string $pathA;
    private static string $pathB;

    public static function setUpBeforeClass(): void
    {
        static::$pathA = sys_get_temp_dir().'/tenancy_test_tenant_a.db';
        static::$pathB = sys_get_temp_dir().'/tenancy_test_tenant_b.db';

        // Remove any leftover files from a prior run (including the shared landlord DB)
        foreach ([
            static::$pathA,
            static::$pathB,
            sys_get_temp_dir().'/tenancy_test_landlord.db',
            sys_get_temp_dir().'/tenancy_test_placeholder.db',
        ] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        static::$kernel = new DoctrineTestKernel('test', false);
        static::$kernel->boot();

        $container = static::$kernel->getContainer();

        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');

        // Create schema for TestProduct in tenant A's SQLite file
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);
        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $container->get('doctrine.orm.tenant_entity_manager');
        $schemaTool = new SchemaTool($tenantEm);
        $schemaTool->createSchema($tenantEm->getMetadataFactory()->getAllMetadata());

        // Create schema for TestProduct in tenant B's SQLite file
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathB]);
        // Must reset the EM after connection switch so metadata cache is tied to new connection
        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $freshEm = $registry->resetManager('tenant');
        $schemaTool = new SchemaTool($freshEm);
        $schemaTool->createSchema($freshEm->getMetadataFactory()->getAllMetadata());

        // Create landlord schema (tenancy_tenants table)
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        $landlordSchemaTool = new SchemaTool($landlordEm);
        $landlordSchemaTool->createSchema($landlordEm->getMetadataFactory()->getAllMetadata());
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();

        foreach ([
            static::$pathA,
            static::$pathB,
            sys_get_temp_dir().'/tenancy_test_landlord.db',
            sys_get_temp_dir().'/tenancy_test_placeholder.db',
        ] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testSwitchToTenantAQueriesHitTenantADatabase(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');

        // Switch to tenant A
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $emA = $registry->resetManager('tenant');

        // Persist a product in tenant A
        $product = new TestProduct('Product A');
        $emA->persist($product);
        $emA->flush();

        // Verify via raw DBAL that tenant A has 1 row
        $count = $conn->fetchOne('SELECT COUNT(*) FROM test_products');
        $this->assertSame('1', (string) $count, 'Tenant A should have 1 product after insert');

        // Switch to tenant B — it should be empty
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathB]);
        $countB = $conn->fetchOne('SELECT COUNT(*) FROM test_products');
        $this->assertSame('0', (string) $countB, 'Tenant B should have 0 products (empty, different DB)');
    }

    public function testSwitchToTenantBDoesNotSeeTenantAData(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');

        // Ensure tenant A has data
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $emA = $registry->resetManager('tenant');
        $emA->persist(new TestProduct('Only In A'));
        $emA->flush();

        // Now switch to tenant B
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathB]);
        $emB = $registry->resetManager('tenant');

        /** @var TestProduct[] $products */
        $products = $emB->getRepository(TestProduct::class)->findAll();

        $this->assertEmpty($products, 'Tenant B EM must return no products — tenant A data must not leak');
    }

    public function testLandlordEmIsUnaffectedByTenantSwitch(): void
    {
        $container = static::$kernel->getContainer();

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');

        // Persist a Tenant entity in the landlord DB
        $tenant = new Tenant('acme', 'Acme Corp');
        $landlordEm->persist($tenant);
        $landlordEm->flush();

        // Switch tenant connection to A and B — landlord must be unaffected
        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathB]);

        // Landlord EM should still find the Tenant we just persisted
        $landlordEm->clear();
        $found = $landlordEm->find(Tenant::class, 'acme');

        $this->assertNotNull($found, 'Landlord EM must find the Tenant entity regardless of tenant switches');
        $this->assertSame('Acme Corp', $found->getName());
    }

    public function testSameTenantSwitchAfterResetReconnects(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');

        // Switch to tenant A and insert a product
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $em = $registry->resetManager('tenant');
        $em->persist(new TestProduct('Persistent Product'));
        $em->flush();

        // Reset and re-switch to the same tenant A
        $conn->reset();
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);
        $em2 = $registry->resetManager('tenant');

        $products = $em2->getRepository(TestProduct::class)->findAll();

        $this->assertNotEmpty($products, 'After re-switch to same tenant, previously persisted data must be accessible');
    }
}
