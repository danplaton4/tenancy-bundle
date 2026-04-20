<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filter;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\TenantMissingException;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestTenantProduct;
use Tenancy\Bundle\Tests\Integration\Support\SharedDbTestKernel;

/**
 * FIX-02 security-critical regression test.
 *
 * After the orchestrator null-branch change (plan 15-02), a shared-DB request that found
 * no resolver match must STILL throw TenantMissingException on any #[TenantAware] entity
 * query when strict_mode is true. Protects against the "drop strict_mode by accident"
 * failure mode where FIX-02 could inadvertently open a data leak.
 *
 * Mirrors the SharedDbFilterIntegrationTest kernel but never calls
 * $tenantContext->setTenant() — simulating the post-FIX-02 "no resolver matched" steady state.
 */
final class StrictModeWithNullResolutionTest extends TestCase
{
    private static SharedDbTestKernel $kernel;
    private static string $dbPath;

    public static function setUpBeforeClass(): void
    {
        static::$dbPath = sys_get_temp_dir().'/tenancy_test_shared_db.db';

        // Remove leftover DB file from prior runs (and from a parallel SharedDbFilterIntegrationTest run)
        if (file_exists(static::$dbPath)) {
            unlink(static::$dbPath);
        }

        static::$kernel = new SharedDbTestKernel('test_strict_null', false);
        static::$kernel->boot();

        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Seed a single tenant row via DBAL (bypasses filter) — presence does not matter,
        // strict_mode blocks the query before scope is evaluated.
        $conn = $em->getConnection();
        $conn->insert('test_tenant_products', ['tenant_id' => 'acme', 'name' => 'Should not be reachable']);
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
        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');
        $em->clear();

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $tenantContext->clear();

        // Inject the (empty) TenantContext into the filter in strict mode,
        // simulating the steady state after Plan 15-02: the orchestrator's null
        // branch did NOT call SharedDriver::boot(), but the bundle-compiled
        // SharedDriver instance runs in strict mode and the filter needs a
        // context reference to evaluate the guard.
        /** @var TenantAwareFilter $filter */
        $filter = $em->getFilters()->getFilter('tenancy_aware');
        $filter->setTenantContext($tenantContext, true);
    }

    public function testQueryingTenantAwareEntityWithoutTenantThrowsTenantMissingException(): void
    {
        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        // Deliberately DO NOT set a tenant. This is the post-FIX-02 steady state for a
        // public/landlord request — resolver chain returned null; TenantContext is empty.
        $this->expectException(TenantMissingException::class);

        $em->getRepository(TestTenantProduct::class)->findAll();
    }

    public function testDqlQueryOnTenantAwareEntityWithoutTenantThrowsTenantMissingException(): void
    {
        $container = static::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        $this->expectException(TenantMissingException::class);

        $em->createQuery(
            "SELECT p FROM Tenancy\\Bundle\\Tests\\Integration\\Support\\Entity\\TestTenantProduct p"
        )->getResult();
    }
}
