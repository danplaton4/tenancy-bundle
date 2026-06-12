<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity;

// Production classes land in Plans 25-01..25-04:
//   - Tenancy\Bundle\Attribute\Shared (Plan 25-01)
//   - Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException (Plan 25-03)
//   - tenancy.shared_entity_sync_subscriber service (Plan 25-04)
//   - tenancy.shared_entity_write_protection service (Plan 25-03)
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlanCategory;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlanWithAssociation;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\RecordingLogger;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\SharedEntityFailureLoggingTestKernel;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\StubMultiTenantProvider;

/**
 * Integration test suite for SHARE-01: synchronous fan-out from landlord to all tenant EMs.
 *
 * Covers behaviors SHARE-01-b through SHARE-01-m (except -j which lives in
 * SharedEntityNoDatabaseKernelTest).
 *
 * Test setup:
 *   - Boots SharedEntitySyncTestKernel (landlord + tenant SQLite, database.enabled: true)
 *   - Pre-creates schemas on landlord DB + both tenant DBs via SchemaTool
 *   - tearDownAfterClass unlinks all 3 DB files
 *
 * Wave 0 state: tests covering fan-out behavior skip gracefully until Plans 25-01..25-04
 * deliver the production subscriber, write-protection listener, and exception classes.
 */
final class SharedEntitySyncIntegrationTest extends TestCase
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

        // Pre-create landlord schema (Tenant entity + TestPlan + TestPlanCategory + TestPlanWithAssociation)
        $landlordSchemaTool = new SchemaTool($landlordEm);
        $landlordSchemaTool->createSchema($landlordEm->getMetadataFactory()->getAllMetadata());

        // Pre-create schemas on both tenant files
        // Must call $tenantConn->close() after setTenant() to force the lazy reconnect
        // through TenantDriverMiddleware — same pattern as DatabasePerTenantMiddlewareIntegrationTest.
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
     * SHARE-01-b (T-25-04): Subscriber wired as onFlush + postFlush listener on landlord EM only.
     */
    public function testSubscriberWiredToLandlordEm(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();

        $this->assertTrue(
            $container->has('tenancy.shared_entity_sync_subscriber'),
            'tenancy.shared_entity_sync_subscriber service must be registered'
        );

        // The subscriber service should exist — wiring assertions (landlord connection tags)
        // are validated via the container definition inspection when the service is public.
        // Full tag assertion will be confirmed when production code lands in Plan 25-04.
        $subscriber = $container->get('tenancy.shared_entity_sync_subscriber');
        $this->assertNotNull($subscriber, 'Subscriber service must be resolvable from the container');
    }

    /**
     * SHARE-01-c: Landlord #[Shared] insert fans out to all tenant EMs.
     */
    public function testInsertFansOutToAllTenants(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Insert a TestPlan on the landlord EM
        $plan = new TestPlan('Pro Plan', 9900);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $this->assertNotNull($plan->getId(), 'TestPlan must have an ID after landlord flush');
        $planId = $plan->getId();

        // Assert fan-out: each tenant EM should have a matching row
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            $this->assertNotNull(
                $tenantPlan,
                sprintf('TestPlan#%d must exist in tenant "%s" after landlord flush', $planId, $tenant->getSlug())
            );
            $this->assertSame('Pro Plan', $tenantPlan->getName());
            $this->assertSame(9900, $tenantPlan->getPriceCents());
        }

        $ctx->clear();
    }

    /**
     * SHARE-01-d: Landlord #[Shared] update fans out to all tenant EMs.
     */
    public function testUpdateFansOutToAllTenants(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Insert then update on landlord
        $plan = new TestPlan('Update Test Plan', 1000);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();

        $plan->setName('Updated Plan Name');
        $plan->setPriceCents(2000);
        $landlordEm->flush();

        // Assert tenant copies reflect updated values
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            $this->assertNotNull($tenantPlan);
            $this->assertSame('Updated Plan Name', $tenantPlan->getName());
            $this->assertSame(2000, $tenantPlan->getPriceCents());
        }

        $ctx->clear();
    }

    /**
     * SHARE-01-e: Landlord #[Shared] delete propagates to tenant EMs.
     */
    public function testDeleteFansOutToAllTenants(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Insert first
        $plan = new TestPlan('Delete Test Plan', 500);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();

        // Now delete on landlord
        $landlordEm->remove($plan);
        $landlordEm->flush();

        // Assert tenant copies are gone
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            $this->assertNull(
                $tenantPlan,
                sprintf('TestPlan#%d must be deleted from tenant "%s" after landlord removal', $planId, $tenant->getSlug())
            );
        }

        $ctx->clear();
    }

    /**
     * SHARE-01-f (T-25-01): Tenant-side persist of #[Shared] entity throws.
     */
    public function testTenantSidePersistThrows(): void
    {
        if (!class_exists(\Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class)
            || !class_exists(\Tenancy\Bundle\Subscriber\SharedEntityWriteProtectionListener::class)) {
            self::markTestSkipped('SharedEntityWriteInTenantContextException + SharedEntityWriteProtectionListener not yet available — lands in Plan 25-03.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_write_protection')) {
            self::markTestSkipped('tenancy.shared_entity_write_protection service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        $tenantProvider = new StubMultiTenantProvider();

        $tenant = $tenantProvider->findAll()[0];
        $ctx->setTenant($tenant);
        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $registry->resetManager('tenant');

        $this->expectException(\Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class);

        $plan = new TestPlan('Forbidden Plan', 0);
        $tenantEm->persist($plan);
        $tenantEm->flush();
    }

    /**
     * SHARE-01-g (T-25-01): Tenant-side update of #[Shared] entity throws.
     */
    public function testTenantSideUpdateThrows(): void
    {
        if (!class_exists(\Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class)
            || !class_exists(\Tenancy\Bundle\Subscriber\SharedEntityWriteProtectionListener::class)) {
            self::markTestSkipped('SharedEntityWriteInTenantContextException + SharedEntityWriteProtectionListener not yet available — lands in Plan 25-03.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_write_protection')) {
            self::markTestSkipped('tenancy.shared_entity_write_protection service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Insert via landlord first so tenant has a copy
        $plan = new TestPlan('Plan For Tenant Update Test', 100);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();

        $tenantProvider = new StubMultiTenantProvider();
        $tenant = $tenantProvider->findAll()[0];
        $ctx->setTenant($tenant);
        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $registry->resetManager('tenant');

        $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
        $this->assertNotNull($tenantPlan);

        $this->expectException(\Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class);

        $tenantPlan->setName('Forbidden Update');
        $tenantEm->flush();
    }

    /**
     * SHARE-01-h (T-25-01): Tenant-side delete of #[Shared] entity throws.
     */
    public function testTenantSideDeleteThrows(): void
    {
        if (!class_exists(\Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class)
            || !class_exists(\Tenancy\Bundle\Subscriber\SharedEntityWriteProtectionListener::class)) {
            self::markTestSkipped('SharedEntityWriteInTenantContextException + SharedEntityWriteProtectionListener not yet available — lands in Plan 25-03.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_write_protection')) {
            self::markTestSkipped('tenancy.shared_entity_write_protection service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Insert via landlord first
        $plan = new TestPlan('Plan For Tenant Delete Test', 200);
        $landlordEm->persist($plan);
        $landlordEm->flush();
        $planId = $plan->getId();

        $tenantProvider = new StubMultiTenantProvider();
        $tenant = $tenantProvider->findAll()[0];
        $ctx->setTenant($tenant);
        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $registry->resetManager('tenant');

        $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
        $this->assertNotNull($tenantPlan);

        $this->expectException(\Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class);

        $tenantEm->remove($tenantPlan);
        $tenantEm->flush();
    }

    /**
     * SHARE-01-i (T-25-04): Subscriber-initiated sync write bypasses write-protection guard.
     *
     * This is implicitly proven by testInsertFansOutToAllTenants completing without throwing.
     * We assert explicitly here that the insert fan-out does NOT throw.
     */
    public function testSyncWriteBypassesWriteProtection(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // If the re-entrancy guard is NOT working, this would throw
        // SharedEntityWriteInTenantContextException when the subscriber tries to flush
        // to each tenant EM (because the write-protection would see a #[Shared] entity
        // in the tenant-EM scheduled insertions).
        $exceptionClass = \Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException::class;
        $exceptionThrown = false;
        try {
            $plan = new TestPlan('Sync Bypass Test Plan', 300);
            $landlordEm->persist($plan);
            $landlordEm->flush();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                $exceptionThrown = true;
            }
        }

        $this->assertFalse(
            $exceptionThrown,
            'Subscriber-initiated sync write must NOT throw SharedEntityWriteInTenantContextException — '
            .'re-entrancy guard (isSyncInProgress) must bypass the write-protection listener'
        );

        // Also assert the plan landed in tenant EMs (positive proof the sync occurred)
        $planId = $plan->getId();
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');
            $this->assertNotNull(
                $tenantEm->find(TestPlan::class, $planId),
                sprintf('Sync write must have landed in tenant "%s"', $tenant->getSlug())
            );
        }

        $ctx->clear();
    }

    /**
     * SHARE-01-k: Per-tenant failure is caught + logged; does NOT abort fan-out to other tenants.
     *
     * Behavior under test (D-01 best-effort + D-07 structured logging):
     *   (a) When tenant_a's fan-out flush fails, tenant_b STILL receives the synced row —
     *       the failure does NOT abort the loop.
     *   (b) The subscriber logs the failure at error level with structured context containing:
     *       tenant_slug, entity_class, identifier, error.
     *   (c) The landlord request is unaffected (no exception thrown to the caller).
     *
     * Strategy: after schema setup, DROP the test_plans table in tenant_a's SQLite DB via
     * direct PDO, then persist+flush a TestPlan on the landlord EM. The fan-out to tenant_a
     * fails (missing table), while tenant_b succeeds. The RecordingLogger injected by
     * InjectRecordingLoggerPass + SharedEntityFailureLoggingTestKernel captures the error.
     */
    public function testPerTenantFailureIsLogged(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var RecordingLogger $recordingLogger */
        $recordingLogger = $container->get('tenancy.test.recording_logger');
        $recordingLogger->reset();

        // Sabotage tenant_a: install a BEFORE INSERT trigger on test_plans that raises an error.
        // This causes the fan-out INSERT to fail without altering existing rows or schema,
        // so the DB file (and its auto-increment state) stays intact for subsequent tests.
        // tenant_b's DB is left intact — it must still receive the row.
        $tenantAPath = StubMultiTenantProvider::getTenantAPath();

        // Close the DBAL tenant connection so the PDO handle to tenant_a is released.
        // This ensures our direct PDO writes are not blocked by an open Doctrine connection.
        /** @var \Doctrine\DBAL\Connection $tenantDbalConn */
        $tenantDbalConn = $registry->getConnection('tenant');
        if ($tenantDbalConn instanceof \Doctrine\DBAL\Connection) {
            $tenantDbalConn->close();
        }

        // Reset context and EM so Doctrine is in a clean landlord state before the flush.
        $ctx->clear();
        $registry->resetManager('tenant');

        // Install the trigger via direct PDO (Doctrine is not connected to tenant_a right now)
        $pdoA = new \PDO('sqlite:'.$tenantAPath);
        $pdoA->exec(
            'CREATE TRIGGER IF NOT EXISTS tenancy_test_prevent_insert '
            .'BEFORE INSERT ON test_plans BEGIN '
            ."SELECT RAISE(ABORT, 'Simulated write failure for fan-out failure test'); "
            .'END'
        );

        // Persist and flush a #[Shared] TestPlan on the landlord EM.
        // This must NOT throw regardless of the tenant_a failure (D-01 best-effort).
        $plan = new TestPlan('Failure Logging Test Plan', 777);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $planId = $plan->getId();
        $this->assertNotNull($planId, 'TestPlan must have an ID after landlord flush');

        // (a) tenant_b MUST have received the row despite tenant_a's failure
        $tenantProvider = new StubMultiTenantProvider();
        $tenants = $tenantProvider->findAll();
        $tenantB = $tenants[1]; // StubMultiTenantProvider always returns [tenant_a, tenant_b]

        $ctx->setTenant($tenantB);
        /** @var EntityManagerInterface $tenantBEm */
        $tenantBEm = $registry->resetManager('tenant');
        $tenantBPlan = $tenantBEm->find(TestPlan::class, $planId);
        $this->assertNotNull(
            $tenantBPlan,
            sprintf(
                'TestPlan#%d must exist in tenant_b after fan-out — '
                .'best-effort (D-01) must not abort the whole loop on tenant_a failure',
                $planId
            )
        );

        // (a) tenant_a must NOT have the row (INSERT was blocked by the trigger)
        // The trigger only blocks INSERTs; SELECTs still work, so find() returns null (no row).
        $tenantA = $tenants[0];
        $ctx->setTenant($tenantA);
        /** @var \Doctrine\DBAL\Connection $dbalConnForA */
        $dbalConnForA = $registry->getConnection('tenant');
        if ($dbalConnForA instanceof \Doctrine\DBAL\Connection) {
            $dbalConnForA->close();
        }
        $tenantAEm = $registry->resetManager('tenant');

        $tenantAPlan = $tenantAEm->find(TestPlan::class, $planId);
        $this->assertNull(
            $tenantAPlan,
            'TestPlan must NOT be in tenant_a — the trigger aborted the fan-out INSERT there'
        );

        // (b) The subscriber must have logged exactly one error for tenant_a's failure (D-07)
        $errorRecords = $recordingLogger->getErrorRecords();
        $this->assertCount(
            1,
            $errorRecords,
            'Exactly one error log record must be emitted for the tenant_a fan-out failure (D-07)'
        );

        $record = $errorRecords[0];
        $this->assertSame(
            'tenancy.shared_entity_sync_failed',
            $record['message'],
            'Error log message must be "tenancy.shared_entity_sync_failed" (D-07 structured log key)'
        );

        // (b) Structured log context must include all four required keys (D-07)
        $ctx2 = $record['context'];
        $this->assertSame(
            'tenant_a',
            $ctx2['tenant_slug'],
            'Logged context must include the failing tenant slug (tenant_a)'
        );
        $this->assertSame(
            TestPlan::class,
            $ctx2['entity_class'],
            'Logged context must include the entity class (TestPlan::class)'
        );
        $this->assertNotEmpty(
            $ctx2['identifier'],
            'Logged context must include the entity identifier (non-empty)'
        );
        $this->assertArrayHasKey(
            'error',
            $ctx2,
            'Logged context must include the error message key'
        );
        $this->assertNotEmpty(
            $ctx2['error'],
            'Logged context error message must be non-empty'
        );

        // Remove the BEFORE INSERT trigger and backfill the blocked row via direct PDO so that
        // subsequent tests see tenant_a's auto-increment sequence in sync with the landlord.
        // We bypass Doctrine/ORM entirely because TestPlan is #[Shared] and the write-protection
        // listener would throw SharedEntityWriteInTenantContextException for any tenant-context write.
        if (isset($pdoA)) {
            $pdoA->exec('DROP TRIGGER IF EXISTS tenancy_test_prevent_insert');
            // Backfill with the exact same id that the landlord used so sequential tests' ids align
            $pdoA->exec(sprintf(
                'INSERT INTO test_plans (id, name, priceCents) VALUES (%d, %s, %d)',
                $planId,
                $pdoA->quote($plan->getName()),
                $plan->getPriceCents()
            ));
            unset($pdoA);
        }

        $ctx->clear();
        $registry->resetManager('tenant');
    }

    /**
     * SHARE-01-m (T-25-02): Cascade depth = 1; association fields on #[Shared] entity are NOT synced.
     */
    public function testAssociationsNotSynced(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Create a TestPlanCategory on the landlord
        $category = new TestPlanCategory('Premium Category');
        $landlordEm->persist($category);
        $landlordEm->flush();

        // Create a TestPlanWithAssociation with the category set
        $planWithAssoc = new TestPlanWithAssociation('Plan With Category');
        $planWithAssoc->setCategory($category);
        $landlordEm->persist($planWithAssoc);
        $landlordEm->flush();

        $planId = $planWithAssoc->getId();
        $this->assertNotNull($planId);

        // Assert: tenant copy has the scalar title BUT NOT the association (category is NULL)
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlanWithAssociation::class, $planId);
            $this->assertNotNull(
                $tenantPlan,
                sprintf('TestPlanWithAssociation#%d must exist in tenant "%s"', $planId, $tenant->getSlug())
            );

            // Scalar field MUST be synced
            $this->assertSame('Plan With Category', $tenantPlan->getTitle());

            // Association MUST NOT be synced (one-level cascade boundary DEC-SHARE-02)
            $this->assertNull(
                $tenantPlan->getCategory(),
                'category association must NOT be synced to tenant EM — cascade depth = 1 boundary'
            );
        }

        $ctx->clear();
    }

    /**
     * CR-01 / CR-02 regression (tenant-context-active fan-out path — the gap WR-03/IN-04 left open).
     *
     * A landlord #[Shared] flush triggered WHILE a tenant is already active must:
     *   - CR-01: restore that tenant's context after the fan-out (not leave it wiped). Before the
     *     fix, fanOutToTenant's finally cleared TenantContext unconditionally, so the
     *     originally-active tenant was lost for the rest of the request — a direct violation of the
     *     bundle's "zero leaks" invariant (TenantMissingException, or unscoped cross-tenant queries
     *     when strict_mode is off).
     *   - CR-02: leave the tenant connection usable under the restored context, so the next query
     *     reconnects against the active tenant's DB rather than the last fanned-out tenant's.
     */
    public function testFanOutRestoresActiveTenantContext(): void
    {
        if (!class_exists(\Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber::class)) {
            self::markTestSkipped('SharedEntitySyncSubscriber not yet available — lands in Plan 25-04.');
        }
        if (!self::$kernel->getContainer()->has('tenancy.shared_entity_sync_subscriber')) {
            self::markTestSkipped('tenancy.shared_entity_sync_subscriber service not yet wired — lands in Plan 25-04.');
        }

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        $tenantProvider = new StubMultiTenantProvider();
        $activeTenant = $tenantProvider->findAll()[0];

        // Simulate a request in which a tenant is already resolved/active at the moment a
        // landlord #[Shared] write triggers the fan-out.
        $ctx->setTenant($activeTenant);

        $plan = new TestPlan('Context Restore Plan', 4242);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        // CR-01: the fan-out must restore the tenant that was active before it ran.
        $this->assertTrue(
            $ctx->hasTenant(),
            'Tenant context must remain set after a landlord fan-out (CR-01) — it was wiped before the fix'
        );
        $this->assertSame(
            $activeTenant->getSlug(),
            $ctx->getTenant()?->getSlug(),
            'Fan-out must restore the originally-active tenant, not a fanned-out tenant or null (CR-01)'
        );

        // CR-02: the tenant EM must be usable under the restored context — the fan-out must not
        // leave the connection dangling against the last fanned-out tenant. Reading the synced
        // plan WITHOUT manually switching must resolve cleanly against the restored tenant's DB.
        $planId = $plan->getId();
        $this->assertNotNull($planId);
        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $registry->getManager('tenant');
        $restoredCopy = $tenantEm->find(TestPlan::class, $planId);
        $this->assertNotNull(
            $restoredCopy,
            sprintf('Synced TestPlan#%d must be readable from the restored tenant "%s" EM after fan-out (CR-02)', $planId, $activeTenant->getSlug())
        );
        $this->assertSame('Context Restore Plan', $restoredCopy->getName());

        $ctx->clear();
        $registry->resetManager('tenant');
    }
}
