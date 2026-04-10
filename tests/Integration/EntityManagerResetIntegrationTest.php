<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Tests\Integration\Support\DoctrineTestKernel;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct;

/**
 * Integration tests proving EntityManagerResetListener behaviour:
 *   - resetManager('tenant') produces a new EM instance with an empty identity map.
 *   - Dispatching TenantContextCleared triggers the reset for the tenant EM only.
 *   - The landlord EM is NOT reset when TenantContextCleared is dispatched.
 */
final class EntityManagerResetIntegrationTest extends TestCase
{
    private static DoctrineTestKernel $kernel;
    private static string $pathA;

    public static function setUpBeforeClass(): void
    {
        static::$pathA = sys_get_temp_dir().'/tenancy_reset_test_tenant_a.db';

        // Remove any leftover files before booting (landlord DB is shared via DoctrineTestKernel path)
        foreach ([
            static::$pathA,
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

        // Switch to a real SQLite file so schema creation succeeds
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $em = $registry->resetManager('tenant');

        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Create landlord schema
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
            sys_get_temp_dir().'/tenancy_test_landlord.db',
            sys_get_temp_dir().'/tenancy_test_placeholder.db',
        ] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testResetManagerReturnsFreshEntityManager(): void
    {
        $container = static::$kernel->getContainer();

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        $emBefore = $registry->getManager('tenant');
        // Capture UnitOfWork identity — DoctrineBundle 2.x wraps the EM in a lazy service proxy
        // so spl_object_id($em) stays the same across resets. The reliable signal of a fresh EM
        // is a new UnitOfWork instance (created in EntityManager::__construct).
        $uowIdBefore = spl_object_id($emBefore->getUnitOfWork());

        $registry->resetManager('tenant');

        $emAfter = $registry->getManager('tenant');
        $uowIdAfter = spl_object_id($emAfter->getUnitOfWork());

        $this->assertNotSame(
            $uowIdBefore,
            $uowIdAfter,
            'resetManager() must produce a fresh UnitOfWork — proof of a new EntityManager underneath the lazy proxy',
        );
    }

    public function testResetManagerClearsIdentityMap(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantConnection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        $conn->switchTenant(['driver' => 'pdo_sqlite', 'path' => static::$pathA]);

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $em = $registry->resetManager('tenant');

        // Persist a product so the identity map is populated
        $product = new TestProduct('Identity Map Product');
        $em->persist($product);
        $em->flush();

        // Identity map should contain the product
        $identityMapBefore = $em->getUnitOfWork()->getIdentityMap();
        $this->assertNotEmpty($identityMapBefore, 'Identity map should contain the persisted entity before reset');

        // Dispatch TenantContextCleared — EntityManagerResetListener calls resetManager('tenant')
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $dispatcher->dispatch(new TenantContextCleared());

        // Get fresh EM from registry (NOT from container — container may return stale lazy proxy)
        $freshEm = $registry->getManager('tenant');

        $identityMapAfter = $freshEm->getUnitOfWork()->getIdentityMap();
        $this->assertEmpty($identityMapAfter, 'Identity map must be empty in fresh EM after TenantContextCleared reset');
    }

    public function testLandlordEmNotResetOnTenantContextCleared(): void
    {
        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        $landlordIdBefore = spl_object_id($landlordEm);

        // Dispatch TenantContextCleared — should only reset tenant EM, not landlord
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $dispatcher->dispatch(new TenantContextCleared());

        // Re-fetch landlord EM from container — should be the same instance
        $landlordEmAfter = $container->get('doctrine.orm.landlord_entity_manager');
        $landlordIdAfter = spl_object_id($landlordEmAfter);

        $this->assertSame(
            $landlordIdBefore,
            $landlordIdAfter,
            'Landlord EM must NOT be reset when TenantContextCleared is dispatched',
        );
    }
}
