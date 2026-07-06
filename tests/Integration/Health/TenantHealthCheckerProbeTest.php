<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Health;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\Health\HealthStatus;
use Tenancy\Bundle\Health\TenantHealthChecker;
use Tenancy\Bundle\Tests\Integration\Support\DoctrineTestKernel;
use Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct;
use Tenancy\Bundle\Tests\Integration\Support\MakeHealthServicesPublicPass;

/**
 * Test kernel extending DoctrineTestKernel to add the health services public pass.
 * Reuses the existing Doctrine/SQLite kernel — no new kernel registered to the container.
 */
final class DoctrineHealthTestKernel extends DoctrineTestKernel
{
    public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MakeHealthServicesPublicPass());
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_doctrine_health_test_'.$this->getEnvironment().'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_doctrine_health_test_'.$this->getEnvironment().'/logs';
    }
}

/**
 * Integration test proving probe-safety invariants for TenantHealthChecker (Success Criterion 2).
 *
 * This test answers the one genuine correctness question from 33-CONTEXT.md:
 * "Does DatabaseSwitchBootstrapper::check() mutate global service state?"
 *
 * Three invariants proven:
 *   1. After a successful probe: TenantContext::hasTenant() === false
 *   2. After a failed probe: TenantContext::hasTenant() === false (finally ran)
 *   3. A subsequent real request reconnects to the correct (different) tenant with no
 *      residual state from the prior probe (no global Connection state mutation)
 *
 * Uses DoctrineTestKernel (existing Doctrine/SQLite test kernel) with two distinct
 * SQLite files as tenant databases.
 */
final class TenantHealthCheckerProbeTest extends TestCase
{
    private static ?DoctrineHealthTestKernel $kernel = null;
    private static string $pathA;
    private static string $pathB;
    private static string $landlordPath;
    private static string $placeholderPath;

    public static function setUpBeforeClass(): void
    {
        self::$pathA = sys_get_temp_dir().'/tenancy_health_probe_test_tenant_a.db';
        self::$pathB = sys_get_temp_dir().'/tenancy_health_probe_test_tenant_b.db';
        self::$landlordPath = sys_get_temp_dir().'/tenancy_test_landlord.db';
        self::$placeholderPath = sys_get_temp_dir().'/tenancy_test_placeholder.db';

        foreach ([self::$pathA, self::$pathB, self::$landlordPath, self::$placeholderPath] as $p) {
            @unlink($p);
        }

        self::$kernel = new DoctrineHealthTestKernel();
        self::$kernel->boot();

        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var Connection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        // Pre-create schemas on both tenant SQLite files.
        foreach ([self::$pathA, self::$pathB] as $path) {
            $tenant = (new Tenant('pre-'.basename($path), basename($path)))
                ->setConnectionConfig(['path' => $path]);
            $ctx->setTenant($tenant);
            $conn->close();

            $em = $registry->resetManager('tenant');
            $schemaTool = new SchemaTool($em);
            $schemaTool->createSchema([$em->getClassMetadata(TestProduct::class)]);
        }

        // Pre-create landlord schema.
        /** @var \Doctrine\ORM\EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        $landlordSchemaTool = new SchemaTool($landlordEm);
        $landlordSchemaTool->createSchema($landlordEm->getMetadataFactory()->getAllMetadata());

        // Clear context so tests start clean.
        $ctx->clear();
        $conn->close();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        foreach ([self::$pathA, self::$pathB, self::$landlordPath, self::$placeholderPath] as $p) {
            @unlink($p);
        }
    }

    protected function setUp(): void
    {
        // Reset context and connection between each test.
        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        $ctx->clear();
        /** @var Connection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        $conn->close();
    }

    /**
     * After a successful probe: TenantContext::hasTenant() === false.
     * This is the core probe-safety invariant (HEALTH-03, Success Criterion 2).
     */
    public function testContextClearedAfterSuccessfulProbe(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantHealthChecker $checker */
        $checker = $container->get(TenantHealthChecker::class);
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        $tenantA = (new Tenant('probe-pass-a', 'Probe Pass A'))
            ->setConnectionConfig(['path' => self::$pathA]);

        $report = $checker->checkOne($tenantA);

        // THE invariant — Success Criterion 2
        $this->assertFalse(
            $ctx->hasTenant(),
            'TenantContext must be cleared after a successful probe (finally block ran)',
        );
        $this->assertSame(HealthStatus::Pass, $report->status, 'Healthy SQLite DB must return Pass status');
        $this->assertSame('probe-pass-a', $report->slug);
    }

    /**
     * After a successful probe for tenantA, a subsequent real request connecting to tenantB
     * must access tenantB's data correctly — proving the probe left no residual connection state.
     *
     * This is the global-state-mutation test (T-33-STATE, Success Criterion 2).
     */
    public function testReconnectsCleanlyAfterProbe(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantHealthChecker $checker */
        $checker = $container->get(TenantHealthChecker::class);
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var Connection $conn */
        $conn = $container->get('doctrine.dbal.tenant_connection');
        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        $tenantA = (new Tenant('reconnect-a', 'Reconnect A'))
            ->setConnectionConfig(['path' => self::$pathA]);
        $tenantB = (new Tenant('reconnect-b', 'Reconnect B'))
            ->setConnectionConfig(['path' => self::$pathB]);

        // Step 1: Run a probe for tenantA.
        $reportA = $checker->checkOne($tenantA);
        $this->assertSame(HealthStatus::Pass, $reportA->status, 'Probe for tenantA must pass');
        $this->assertFalse($ctx->hasTenant(), 'Context must be clear after probe');

        // Step 2: Simulate a subsequent real request for tenantB.
        // Mimic DatabaseSwitchBootstrapper::boot() + TenantContextOrchestrator behavior:
        //   setTenant() + close() to force DBAL lazy-reconnect to tenantB's DB.
        $ctx->setTenant($tenantB);
        $conn->close(); // DatabaseSwitchBootstrapper::boot() equivalent
        $emB = $registry->resetManager('tenant');

        // Insert a row in tenantB's DB to confirm we're connected to the right DB.
        $product = new TestProduct('only-in-B-after-probe');
        $emB->persist($product);
        $emB->flush();

        // Read back from the current connection — must see tenantB's row, not tenantA's.
        $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM test_products');
        $this->assertGreaterThanOrEqual(
            1,
            $count,
            'After probe of tenantA, subsequent tenantB request must connect to tenantB DB — no residual state from probe',
        );

        // Verify tenantA's DB does NOT have the row we inserted while connected to tenantB.
        $ctx->setTenant($tenantA);
        $conn->close();
        $countA = (int) $conn->fetchOne('SELECT COUNT(*) FROM test_products');
        // tenantA had 0 rows (we only created schema, no inserts in this test).
        $this->assertSame(
            0,
            $countA,
            'TenantA DB must not contain rows inserted while connected to tenantB — data isolation intact after probe',
        );

        // Clean up context.
        $ctx->clear();
        $conn->close();
    }

    /**
     * After a failed probe (invalid/unreachable DB path): TenantContext::hasTenant() === false.
     * The finally block runs even when check() fails with a DBAL exception.
     */
    public function testContextClearedAfterFailedProbe(): void
    {
        $container = self::$kernel->getContainer();
        /** @var TenantHealthChecker $checker */
        $checker = $container->get(TenantHealthChecker::class);
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Point tenant at a path that cannot exist (directory that is not writable).
        // SQLite will fail to open a DB at a non-existent nested path.
        $badPath = sys_get_temp_dir().'/tenancy_does_not_exist_directory/probe_fail.db';

        $badTenant = (new Tenant('probe-fail', 'Probe Fail'))
            ->setConnectionConfig(['path' => $badPath]);

        $report = $checker->checkOne($badTenant);

        // THE invariant — even when probe fails, finally ran and context is clear.
        $this->assertFalse(
            $ctx->hasTenant(),
            'TenantContext must be cleared even when the probe fails (finally block ran)',
        );
        $this->assertSame(HealthStatus::Fail, $report->status, 'Unreachable DB must return Fail status');
        $this->assertSame('probe-fail', $report->slug);
    }
}
