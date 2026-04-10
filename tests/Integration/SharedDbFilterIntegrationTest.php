<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Driver\SharedDriver;
use Tenancy\Bundle\Exception\TenantMissingException;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestTenantProduct;
use Tenancy\Bundle\Tests\Integration\Support\SharedDbTestKernel;

/**
 * End-to-end integration tests proving shared-DB filter scoping.
 *
 * Verifies:
 *   - TenantAware entities are automatically filtered by active tenant_id
 *   - Switching tenant context changes which rows are returned
 *   - Non-TenantAware entities return all rows regardless of tenant context
 *   - Strict mode throws TenantMissingException when no tenant is active
 *   - DQL queries are also filtered by the active tenant
 */
final class SharedDbFilterIntegrationTest extends TestCase
{
    private static SharedDbTestKernel $kernel;
    private static string $dbPath;

    public static function setUpBeforeClass(): void
    {
        static::$dbPath = sys_get_temp_dir().'/tenancy_test_shared_db.db';

        // Remove leftover DB file from prior runs
        if (file_exists(static::$dbPath)) {
            unlink(static::$dbPath);
        }

        static::$kernel = new SharedDbTestKernel('test', false);
        static::$kernel->boot();

        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Seed data via DBAL (bypasses the filter)
        $conn = $em->getConnection();
        $conn->insert('test_tenant_products', ['tenant_id' => 'acme', 'name' => 'Acme Widget']);
        $conn->insert('test_tenant_products', ['tenant_id' => 'acme', 'name' => 'Acme Gadget']);
        $conn->insert('test_tenant_products', ['tenant_id' => 'globex', 'name' => 'Globex Item']);
        $conn->insert('test_products', ['name' => 'Shared Product']);
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
        // Clear identity map between tests to avoid stale cached results
        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');
        $em->clear();

        // Clear tenant context to start each test from a clean state
        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makeTenant(string $slug): TenantInterface
    {
        return new class($slug) implements TenantInterface {
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

    private function bootForTenant(string $slug): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->setTenant($this->makeTenant($slug));

        /** @var SharedDriver $driver */
        $driver = $container->get('tenancy.shared_driver');
        $driver->boot($this->makeTenant($slug));
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    public function testTenantAwareEntityFilteredByActiveTenant(): void
    {
        $this->bootForTenant('acme');

        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        /** @var TestTenantProduct[] $products */
        $products = $em->getRepository(TestTenantProduct::class)->findAll();

        $this->assertCount(2, $products, 'acme should see exactly 2 products');
        foreach ($products as $product) {
            $this->assertSame('acme', $product->getTenantId(), 'All returned products must belong to acme');
        }
    }

    public function testSwitchingTenantChangesFilterScope(): void
    {
        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        // Verify acme sees 2 products
        $this->bootForTenant('acme');
        $acmeProducts = $em->getRepository(TestTenantProduct::class)->findAll();
        $this->assertCount(2, $acmeProducts, 'acme should see 2 products');

        // Switch to globex and verify scoping changes
        $em->clear();
        $this->bootForTenant('globex');
        $globexProducts = $em->getRepository(TestTenantProduct::class)->findAll();

        $this->assertCount(1, $globexProducts, 'globex should see exactly 1 product');
        $this->assertSame('globex', $globexProducts[0]->getTenantId());
        $this->assertSame('Globex Item', $globexProducts[0]->getName());
    }

    public function testNonTenantAwareEntityUnaffectedByFilter(): void
    {
        $this->bootForTenant('acme');

        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        /** @var TestProduct[] $products */
        $products = $em->getRepository(TestProduct::class)->findAll();

        $this->assertCount(1, $products, 'Non-TenantAware entity must return all rows regardless of tenant context');
        $this->assertSame('Shared Product', $products[0]->getName());
    }

    public function testStrictModeThrowsWhenNoTenantActive(): void
    {
        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');
        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');

        // Inject TenantContext into the filter (no tenant set) so filter runs in strict mode
        /** @var \Tenancy\Bundle\Filter\TenantAwareFilter $filter */
        $filter = $em->getFilters()->getFilter('tenancy_aware');
        $filter->setTenantContext($tenantContext, true);

        // TenantContext has no tenant at this point — strict mode must throw
        $this->expectException(TenantMissingException::class);

        $em->getRepository(TestTenantProduct::class)->findAll();
    }

    public function testFilterScopeAppliedInDqlQuery(): void
    {
        $this->bootForTenant('acme');

        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        // DQL query matching an acme-owned product
        $acmeResult = $em->createQuery(
            "SELECT p FROM Tenancy\\Bundle\\Tests\\Integration\\Support\\Entity\\TestTenantProduct p WHERE p.name = 'Acme Widget'"
        )->getResult();

        $this->assertCount(1, $acmeResult, 'DQL must find Acme Widget when acme is active tenant');

        // DQL query targeting a globex product — should return 0 due to filter
        $globexResult = $em->createQuery(
            "SELECT p FROM Tenancy\\Bundle\\Tests\\Integration\\Support\\Entity\\TestTenantProduct p WHERE p.name = 'Globex Item'"
        )->getResult();

        $this->assertCount(0, $globexResult, 'DQL must return 0 rows for Globex Item when acme is active tenant');
    }
}
