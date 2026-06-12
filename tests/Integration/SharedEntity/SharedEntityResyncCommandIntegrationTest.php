<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\SharedEntityFailureLoggingTestKernel;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\StubMultiTenantProvider;

/**
 * Integration test stubs for SHARE-02: resync command behaviors (SHARE-02-h, -i, -l).
 *
 * All tests are skip-guarded on class_exists(SharedEntityResyncCommand::class) so the
 * suite stays green until Plan 26-03 delivers the production command.
 *
 * Reuses SharedEntityFailureLoggingTestKernel directly — MakeSharedEntityServicesPublicPass
 * (already registered in its parent build()) was extended in Plan 26-01 to also expose
 * tenancy.shared_entity_copier, tenancy.command.shared_resync, and
 * doctrine.dbal.tenant_connection. No new kernel subclass is needed.
 *
 * Test setup:
 *   - Boots SharedEntityFailureLoggingTestKernel (inherits landlord + tenant SQLite)
 *   - Pre-creates schemas on landlord DB + both tenant DBs via SchemaTool
 *   - tearDownAfterClass shuts down kernel and unlinks all DB files
 */
final class SharedEntityResyncCommandIntegrationTest extends TestCase
{
    private static ?SharedEntityFailureLoggingTestKernel $kernel = null;
    private static string $landlordPath;
    private static string $tenantAPath;
    private static string $tenantBPath;
    private static string $placeholderPath;

    public static function setUpBeforeClass(): void
    {
        self::$landlordPath = sys_get_temp_dir().'/tenancy_shared_test_landlord.db';
        self::$tenantAPath = StubMultiTenantProvider::getTenantAPath();
        self::$tenantBPath = StubMultiTenantProvider::getTenantBPath();
        self::$placeholderPath = sys_get_temp_dir().'/tenancy_shared_test_placeholder.db';

        // Remove stale DB files before boot
        foreach ([self::$landlordPath, self::$tenantAPath, self::$tenantBPath, self::$placeholderPath] as $path) {
            @unlink($path);
        }

        self::$kernel = new SharedEntityFailureLoggingTestKernel();
        self::$kernel->boot();

        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var \Doctrine\DBAL\Connection $tenantConn */
        $tenantConn = $container->get('doctrine.dbal.tenant_connection');

        // Pre-create landlord schema
        $landlordSchemaTool = new SchemaTool($landlordEm);
        $landlordSchemaTool->createSchema($landlordEm->getMetadataFactory()->getAllMetadata());

        // Pre-create schemas on both tenant files
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            $tenantConn->close();
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');
            $schemaTool = new SchemaTool($tenantEm);
            $schemaTool->createSchema($tenantEm->getMetadataFactory()->getAllMetadata());
        }

        // Clean context so tests start from a landlord state
        $ctx->clear();
        $registry->resetManager('tenant');
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        foreach ([self::$landlordPath, self::$tenantAPath, self::$tenantBPath, self::$placeholderPath] as $path) {
            @unlink($path);
        }
    }

    protected function setUp(): void
    {
        // Reset tenant context before each test
        $container = self::$kernel->getContainer();
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        $ctx->clear();
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        $registry->resetManager('tenant');
    }

    /**
     * SHARE-02-h: Idempotency — re-running after full sync produces no duplicate rows (D-02 find-or-new).
     */
    public function testResyncIsIdempotent(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-i: Write-protection bypass — copier writes to tenant EM without throwing (LANDMINE).
     */
    public function testResyncWritesBypassWriteProtection(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-l: Drift classification correctness — in-sync rows not counted as update (D-03).
     */
    public function testInSyncRowsNotCountedAsUpdate(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }
}
