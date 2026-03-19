<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\BootstrapperTestKernel;

/**
 * Integration tests proving DoctrineBootstrapper identity map isolation.
 *
 * Verifies:
 *   - DoctrineBootstrapper is registered in the DI container under tenancy.doctrine_bootstrapper
 *   - boot() clears the EntityManager identity map
 *   - clear() clears the EntityManager identity map
 *   - resetManager() via ManagerRegistry produces a fresh EM (new UoW spl_object_id)
 */
final class DoctrineBootstrapperIntegrationTest extends TestCase
{
    private static BootstrapperTestKernel $kernel;
    private static string $dbPath;

    public static function setUpBeforeClass(): void
    {
        static::$dbPath = sys_get_temp_dir() . '/tenancy_bootstrapper_test.db';

        // Remove leftover DB file from prior runs
        if (file_exists(static::$dbPath)) {
            unlink(static::$dbPath);
        }

        static::$kernel = new BootstrapperTestKernel('test', false);
        static::$kernel->boot();

        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em         = $container->get('doctrine.orm.default_entity_manager');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();

        if (file_exists(static::$dbPath)) {
            unlink(static::$dbPath);
        }
    }

    protected function setUp(): void
    {
        // Clear identity map and tenant context between tests
        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');
        $em->clear();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();
    }

    private function makeTenantStub(string $slug): TenantInterface
    {
        return new class ($slug) implements TenantInterface {
            public function __construct(private readonly string $slug)
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getDomain(): ?string
            {
                return null;
            }

            /** @return array<string, mixed> */
            public function getConnectionConfig(): array
            {
                return [];
            }

            public function getName(): string
            {
                return $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }
        };
    }

    public function testDoctrineBootstrapperIsRegisteredInContainer(): void
    {
        $container    = static::$kernel->getContainer();
        $bootstrapper = $container->get('tenancy.doctrine_bootstrapper');

        $this->assertInstanceOf(DoctrineBootstrapper::class, $bootstrapper);
    }

    public function testBootClearsIdentityMap(): void
    {
        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        // Persist a Tenant entity so the identity map is populated
        $tenant = new Tenant('alpha', 'Alpha Corp');
        $em->persist($tenant);
        $em->flush();

        // Load the entity to ensure it is in the identity map
        $em->clear();
        $loaded = $em->find(Tenant::class, 'alpha');
        $this->assertNotNull($loaded, 'Tenant must be loadable after persist+flush');

        // Identity map should contain the Tenant entry
        $mapBefore = $em->getUnitOfWork()->getIdentityMap();
        $this->assertNotEmpty($mapBefore, 'Identity map must be non-empty after loading entity');

        // Call boot() on DoctrineBootstrapper with a different tenant stub
        /** @var DoctrineBootstrapper $bootstrapper */
        $bootstrapper = $container->get('tenancy.doctrine_bootstrapper');
        $bootstrapper->boot($this->makeTenantStub('beta'));

        // Identity map must be empty after boot()
        $mapAfter = $em->getUnitOfWork()->getIdentityMap();
        $this->assertEmpty(
            $mapAfter,
            'Identity map must be empty after DoctrineBootstrapper::boot() — cross-tenant leak prevented',
        );
    }

    public function testClearClearsIdentityMap(): void
    {
        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        // Persist and load a Tenant entity to populate the identity map
        $tenant = new Tenant('gamma', 'Gamma Inc');
        $em->persist($tenant);
        $em->flush();

        $em->clear();
        $loaded = $em->find(Tenant::class, 'gamma');
        $this->assertNotNull($loaded, 'Tenant must be loadable after persist+flush');

        // Identity map should contain the Tenant entry
        $mapBefore = $em->getUnitOfWork()->getIdentityMap();
        $this->assertNotEmpty($mapBefore, 'Identity map must be non-empty after loading entity');

        // Call clear() on DoctrineBootstrapper
        /** @var DoctrineBootstrapper $bootstrapper */
        $bootstrapper = $container->get('tenancy.doctrine_bootstrapper');
        $bootstrapper->clear();

        // Identity map must be empty after clear()
        $mapAfter = $em->getUnitOfWork()->getIdentityMap();
        $this->assertEmpty(
            $mapAfter,
            'Identity map must be empty after DoctrineBootstrapper::clear() — cross-tenant leak prevented',
        );
    }

    public function testEntityManagerResetListenerResetsDefaultEM(): void
    {
        $container = static::$kernel->getContainer();

        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        $emBefore  = $registry->getManager();
        $uowBefore = spl_object_id($emBefore->getUnitOfWork());

        // Simulate what EntityManagerResetListener does: resetManager() with no argument
        $registry->resetManager();

        $emAfter  = $registry->getManager();
        $uowAfter = spl_object_id($emAfter->getUnitOfWork());

        $this->assertNotSame(
            $uowBefore,
            $uowAfter,
            'resetManager() must produce a fresh UnitOfWork — proof of a new EntityManager underneath the lazy proxy',
        );
    }
}
