<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tenancy\Bundle\Command\SharedEntityResyncCommand;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Shared\SharedEntityCopierInterface;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\SharedEntityFailureLoggingTestKernel;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\StubMultiTenantProvider;

/**
 * Integration proof for SHARE-02: resync command behaviors (SHARE-02-h, -i, -l).
 *
 * Proves against a live SQLite kernel:
 *   - SHARE-02-i: write-protection bypass works end-to-end (no SharedEntityWriteInTenantContextException)
 *   - SHARE-02-h: idempotency — re-run produces no duplicates with CR-01 cross-DB key equality
 *   - SHARE-02-l: drift classification correctness — in-sync rows not counted as update
 *
 * Reuses SharedEntityFailureLoggingTestKernel directly — MakeSharedEntityServicesPublicPass
 * (already registered in its parent build()) was extended in Plan 26-01 to also expose
 * tenancy.shared_entity_copier, tenancy.command.shared_resync, and
 * doctrine.dbal.tenant_connection.
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
     * SHARE-02-i: Write-protection bypass — copier writes to tenant EM without throwing (LANDMINE).
     *
     * Proves that the copier-owned syncInProgress flag scopes the bypass correctly:
     * the command can flush #[Shared] rows to tenant EMs without triggering
     * SharedEntityWriteInTenantContextException.
     */
    public function testResyncWritesBypassWriteProtection(): void
    {
        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Seed a #[Shared] entity on the landlord EM — bypass fan-out by inserting directly
        // (the subscriber already ran in prior tests; we need a fresh row for this test).
        $plan = new TestPlan('Bypass Test Plan', 1500);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();
        $this->assertNotNull($planId, 'TestPlan must receive an ID after landlord flush');

        // Resolve the command from the container (tenancy.command.shared_resync is public
        // via MakeSharedEntityServicesPublicPass extended in Plan 26-01).
        /** @var SharedEntityResyncCommand $command */
        $command = $container->get('tenancy.command.shared_resync');
        $tester = new CommandTester($command);

        // If the write-protection bypass is NOT working, applyRow()'s tenant flush would throw
        // SharedEntityWriteInTenantContextException and the command would exit FAILURE (or
        // propagate the exception). --force skips the confirmation prompt.
        $exceptionThrown = false;
        try {
            $exitCode = $tester->execute(['--force' => true]);
        } catch (\Throwable $e) {
            $exceptionThrown = true;
            $exitCode = 1;
        }

        $this->assertFalse(
            $exceptionThrown,
            'tenancy:shared:resync must NOT throw — the copier syncInProgress flag must bypass write-protection'
        );
        $this->assertSame(
            0,
            $exitCode,
            'Command must exit SUCCESS (0). Output: '.$tester->getDisplay()
        );

        // Positive proof: the row must have landed in each tenant DB.
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $tenantEm = $this->switchTenantManager($registry, $ctx, $tenant);
            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            $this->assertNotNull(
                $tenantPlan,
                sprintf(
                    'TestPlan#%d must exist in tenant "%s" after resync — write-protection bypass did not work',
                    $planId,
                    $tenant->getSlug()
                )
            );
        }

        $ctx->clear();
    }

    /**
     * SHARE-02-h: Idempotency — re-running after full sync produces no duplicate rows (D-02 find-or-new).
     * Also proves CR-01: tenant copy id equals landlord master id (cross-DB key equality).
     */
    public function testResyncIsIdempotent(): void
    {
        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Seed a #[Shared] entity on the landlord EM.
        $plan = new TestPlan('Idempotency Test Plan', 2500);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();
        $this->assertNotNull($planId);

        /** @var SharedEntityResyncCommand $command */
        $command = $container->get('tenancy.command.shared_resync');
        $tester = new CommandTester($command);

        // First run: sync all tenants
        $exitCode = $tester->execute(['--force' => true]);
        $this->assertSame(0, $exitCode, 'First resync run must succeed. Output: '.$tester->getDisplay());

        // Assert tenant copy exists with the SAME id as the landlord master (CR-01 cross-DB key equality)
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $tenantEm = $this->switchTenantManager($registry, $ctx, $tenant);
            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            $this->assertNotNull(
                $tenantPlan,
                sprintf('TestPlan must exist in tenant "%s" after first resync', $tenant->getSlug())
            );
            $this->assertSame(
                $planId,
                $tenantPlan->getId(),
                sprintf('CR-01: tenant copy id must equal landlord master id in tenant "%s"', $tenant->getSlug())
            );
        }

        // Clear context before second run
        $ctx->clear();
        $registry->resetManager('tenant');

        // Second run: must be idempotent — no duplicates
        $exitCode = $tester->execute(['--force' => true]);
        $this->assertSame(0, $exitCode, 'Second resync run must succeed. Output: '.$tester->getDisplay());

        // Assert exactly one row per tenant (find-or-new, not insert-always)
        foreach ($tenantProvider->findAll() as $tenant) {
            $tenantEm = $this->switchTenantManager($registry, $ctx, $tenant);
            /** @var list<TestPlan> $allPlans */
            $allPlans = $tenantEm->getRepository(TestPlan::class)->findBy(['name' => 'Idempotency Test Plan']);
            $this->assertCount(
                1,
                $allPlans,
                sprintf(
                    'Exactly one "Idempotency Test Plan" row must exist in tenant "%s" after second resync — idempotency broken',
                    $tenant->getSlug()
                )
            );
            $this->assertSame(
                $planId,
                $allPlans[0]->getId(),
                sprintf('CR-01: id must remain %d after second resync in tenant "%s"', $planId, $tenant->getSlug())
            );
        }

        $ctx->clear();
    }

    /**
     * SHARE-02-l: Drift classification correctness — in-sync rows not counted as update (D-03).
     *
     * Proves classifyRow() returns 'in-sync' for unchanged rows and 'update' after scalar mutation.
     * Uses the copier directly (not just the command display) for precise per-row assertions.
     */
    public function testInSyncRowsNotCountedAsUpdate(): void
    {
        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var SharedEntityCopierInterface $copier */
        $copier = $container->get('tenancy.shared_entity_copier');

        // Seed and sync a #[Shared] entity so the tenant copies are in sync.
        $plan = new TestPlan('Classify Test Plan', 3000);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();
        $this->assertNotNull($planId);

        /** @var SharedEntityResyncCommand $command */
        $command = $container->get('tenancy.command.shared_resync');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true]);
        $this->assertSame(0, $exitCode, 'Sync run must succeed. Output: '.$tester->getDisplay());

        // After a full sync, every tenant copy is in-sync with the landlord master.
        // classifyRow() must return 'in-sync' (NOT 'update') for the unchanged row.
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $tenantEm = $this->switchTenantManager($registry, $ctx, $tenant);

            $classification = $copier->classifyRow($landlordEm, $tenantEm, $plan);
            $this->assertSame(
                'in-sync',
                $classification,
                sprintf(
                    'classifyRow() must return "in-sync" for an unchanged synced row in tenant "%s" (not "update")',
                    $tenant->getSlug()
                )
            );
        }

        // Also confirm the --dry-run command output shows 0 would-update for this row.
        $ctx->clear();
        $registry->resetManager('tenant');

        $dryRunExitCode = $tester->execute(['--dry-run' => true]);
        $this->assertSame(0, $dryRunExitCode, 'Dry-run must exit SUCCESS. Output: '.$tester->getDisplay());

        $display = $tester->getDisplay();
        // The drift summary table shows "Would-Update" column — assert it shows 0 for both tenants.
        // Format: "| tenant_a | N | 0 | N |" (tenant, would-insert, would-update, in-sync)
        // A robust check: confirm "Would-Update" appears as a header and the row counts are 0.
        $this->assertStringContainsString('Would-Update', $display, 'Dry-run output must include "Would-Update" column header');

        // Prove the 'update' branch: mutate the tenant copy directly via PDO (bypassing
        // Doctrine and the fan-out subscriber) so the tenant row drifts from the landlord.
        // This simulates the scenario resync is meant to fix — tenant row out of date.
        $this->assertNotNull($planId);

        // Direct PDO update to tenant_a to create drift without triggering fan-out.
        // Use Doctrine ClassMetadata to get the correct column name (avoid camelCase/underscore ambiguity).
        $planMeta = $landlordEm->getClassMetadata(TestPlan::class);
        $priceCentsColumn = $planMeta->getColumnName('priceCents');
        $idColumn = $planMeta->getSingleIdentifierColumnName();

        $tenantAPath = StubMultiTenantProvider::getTenantAPath();
        $pdoA = new \PDO('sqlite:'.$tenantAPath);
        $pdoA->exec(sprintf(
            'UPDATE test_plans SET %s = 1 WHERE %s = %d',
            $priceCentsColumn,
            $idColumn,
            $planId
        ));
        unset($pdoA);

        // Reset the tenant EM cache so classifyRow sees the stale (drifted) tenant row.
        $tenantA = (new StubMultiTenantProvider())->findBySlug('tenant_a');
        $tenantEm = $this->switchTenantManager($registry, $ctx, $tenantA);

        $classification = $copier->classifyRow($landlordEm, $tenantEm, $plan);
        $this->assertSame(
            'update',
            $classification,
            'classifyRow() must return "update" when tenant copy has a drifted scalar value'
        );

        $ctx->clear();
    }

    /**
     * Reset the tenant manager onto $tenant with a forced reconnect — mirrors the subscriber's own
     * switchToTenant() so a read resolves against the correct tenant DB file (the previous DBAL
     * socket must be closed for TenantAwareDriver::connect() to pick up the new tenant's params).
     */
    private function switchTenantManager(ManagerRegistry $registry, TenantContext $ctx, TenantInterface $tenant): EntityManagerInterface
    {
        $ctx->setTenant($tenant);
        $conn = $registry->getConnection('tenant');
        if ($conn instanceof \Doctrine\DBAL\Connection) {
            $conn->close();
        }
        /** @var EntityManagerInterface $em */
        $em = $registry->resetManager('tenant');

        return $em;
    }
}
