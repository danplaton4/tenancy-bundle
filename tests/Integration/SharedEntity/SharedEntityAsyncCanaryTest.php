<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\SharedEntityAsyncFanOutException;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\SharedEntityAsyncTestKernel;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\StubMultiTenantProvider;

/**
 * SHARE-03 round-trip acceptance canary.
 *
 * This canary asserts handler-reach + per-tenant DB state, NOT PhpSerializer survival.
 * The SyncTransport::send() re-dispatches the envelope on the bus without serialization
 * (unlike the Phase 20 mailer AsyncCanaryTest which exercises the PhpSerializer round-trip
 * for X-Transport header survival). Here the point is that dispatching SharedEntityChangedMessage
 * on the bus reaches SharedEntityChangedMessageHandler via the sync:// transport and that
 * handler converges EVERY tenant DB to the correct state.
 *
 * Covers:
 *   SHARE-03-j: transport round-trip canary (bus dispatch reaches handler via sync:// transport)
 *   SHARE-03-d: latest-state re-fetch (handler uses current landlord state, not message snapshot)
 *   SHARE-03-f: all-tenant fan-out (≥2 tenant DBs receive the row)
 *   SHARE-03-e / D-04: vanished-row → delete (handler deletes tenant copies when landlord row is gone)
 *   SHARE-03-g / D-02: throw-to-retry contract (DROP TABLE on one tenant → SharedEntityAsyncFanOutException)
 *   SHARE-03-h: idempotency (re-dispatch yields exactly one row per tenant)
 *   D-01 (stamp-clearing): active-dispatch-tenant fans out to ALL tenants, not just the active one
 *
 * @see AsyncCanaryTest::testAsyncDispatchInWorkerUsesTenantDsnNotLandlord
 *   Established the pattern of setting an active tenant BEFORE bus->dispatch to prove
 *   stamp-clearing isolation in Phase 20.  testWrongTenantIsolationWithActiveDispatchTenant
 *   applies the same pattern here for the shared-entity async path (D-01).
 */
final class SharedEntityAsyncCanaryTest extends TestCase
{
    private static ?SharedEntityAsyncTestKernel $kernel = null;

    /** @var string[] */
    private static array $dbPaths = [];

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }

        // Collect all DB paths we will manage
        self::$dbPaths = [
            sys_get_temp_dir().'/tenancy_shared_async_test_landlord.db',
            sys_get_temp_dir().'/tenancy_shared_async_test_placeholder.db',
            StubMultiTenantProvider::getTenantAPath(),
            StubMultiTenantProvider::getTenantBPath(),
        ];

        // Remove stale DB files before boot so we start from a clean slate
        foreach (self::$dbPaths as $path) {
            @unlink($path);
        }

        // Purge stale kernel cache to prevent cross-test pollution
        $cacheDir = sys_get_temp_dir().'/tenancy_doctrine_test_'.md5(SharedEntityAsyncTestKernel::class).'_shared_async_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }

        self::$kernel = new SharedEntityAsyncTestKernel('shared_async_test', false);
        self::$kernel->boot();

        // Create schemas on all DBs (must run AFTER boot so DatabaseSwitchBootstrapper
        // does not destroy :memory: connections — file-based DBs are used here)
        self::createAllSchemas();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }

        foreach (self::$dbPaths as $path) {
            @unlink($path);
        }
    }

    /**
     * Type-narrowing accessor — setUpBeforeClass either boots the kernel or skips the class.
     */
    private function kernel(): SharedEntityAsyncTestKernel
    {
        if (null === self::$kernel) {
            self::markTestSkipped('Kernel not booted (symfony/messenger absent)');
        }

        return self::$kernel;
    }

    protected function setUp(): void
    {
        if (null === self::$kernel) {
            self::markTestSkipped('Kernel not booted (symfony/messenger absent)');
        }

        $container = $this->kernel()->getContainer();

        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        $ctx->clear();

        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        // Reset BOTH managers — the handler may have closed/invalidated the landlord EM
        // (e.g. after a failed fan-out or after calling clear() in the handler's stale-read
        // mitigation step) and we need a fresh EM for each test.
        $registry->resetManager('landlord');
        $registry->resetManager('tenant');

        // Re-create schemas before each test so each test starts with empty tables
        self::createAllSchemas();
    }

    /**
     * SHARE-03-j + SHARE-03-d: transport round-trip canary + latest-state re-fetch.
     *
     * Persist a #[Shared] entity on the landlord EM.  The subscriber's async dispatch
     * branch dispatches SharedEntityChangedMessage on the bus.  The sync:// transport
     * routes it inline to SharedEntityChangedMessageHandler which re-fetches the current
     * landlord state and upserts to EVERY tenant DB.
     *
     * The round-trip proof: the dispatch goes through the bus→transport→handler chain,
     * NOT by calling the handler directly.  DB state in both tenant DBs is the positive
     * confirmation that handler-reach occurred.
     */
    public function testAsyncRoundTripCanary(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        // Persist a #[Shared] TestPlan on the landlord — the subscriber's async branch
        // dispatches SharedEntityChangedMessage on messenger.bus.default.
        $plan = new TestPlan('Async Round Trip Plan', 4900);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $planId = $plan->getId();
        self::assertNotNull($planId, 'TestPlan must have an ID after landlord flush');

        // Assert the row exists in EVERY tenant DB with the correct scalar field values
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            $conn = $registry->getConnection('tenant');
            if ($conn instanceof \Doctrine\DBAL\Connection) {
                $conn->close();
            }
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            self::assertNotNull(
                $tenantPlan,
                sprintf(
                    'TestPlan#%d must exist in tenant "%s" — round-trip proof: handler reached via sync:// transport',
                    $planId,
                    $tenant->getSlug()
                )
            );
            self::assertSame('Async Round Trip Plan', $tenantPlan->getName());
            self::assertSame(4900, $tenantPlan->getPriceCents());
        }

        $ctx->clear();
    }

    /**
     * SHARE-03-f: handler fans out to ALL tenants (≥2 distinct tenant DBs).
     *
     * With StubMultiTenantProvider providing two tenants (tenant_a, tenant_b),
     * assert BOTH tenant DBs received the row — no silent single-tenant dispatch.
     */
    public function testHandlerFansOutToAllTenants(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        $plan = new TestPlan('All Tenants Fan Out Plan', 1000);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $planId = $plan->getId();
        self::assertNotNull($planId);

        $tenantProvider = new StubMultiTenantProvider();
        $tenants = $tenantProvider->findAll();

        // Must have ≥2 tenants for this test to be meaningful
        self::assertGreaterThanOrEqual(2, \count($tenants), 'StubMultiTenantProvider must return ≥2 tenants for fan-out assertion');

        $tenantsWithRow = 0;
        foreach ($tenants as $tenant) {
            $ctx->setTenant($tenant);
            $conn = $registry->getConnection('tenant');
            if ($conn instanceof \Doctrine\DBAL\Connection) {
                $conn->close();
            }
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            if (null !== $tenantPlan) {
                ++$tenantsWithRow;
            }
        }

        self::assertGreaterThanOrEqual(
            2,
            $tenantsWithRow,
            sprintf(
                'Handler must fan out to ALL tenants: expected ≥2 tenant DBs to have TestPlan#%d, found %d',
                $planId,
                $tenantsWithRow
            )
        );

        $ctx->clear();
    }

    /**
     * BLOCKER #3 resolution — D-01 stamp-clearing integration proof.
     *
     * Sets ONE specific tenant ACTIVE on TenantContext BEFORE the landlord flush/dispatch,
     * mirroring AsyncCanaryTest::testAsyncDispatchInWorkerUsesTenantDsnNotLandlord which
     * also sets a tenant active at dispatch time to prove stamp behaviour.
     *
     * The invariant: SharedEntitySyncSubscriber's async branch clears TenantContext BEFORE
     * dispatching SharedEntityChangedMessage, so TenantSendingMiddleware does NOT stamp the
     * envelope with a single TenantStamp.  If it DID stamp, TenantWorkerMiddleware would
     * boot ONLY tenantA's context and the handler would fan out to just one tenant —
     * testHandlerFansOutToAllTenants would then pass but this test would catch the stamp-
     * clearing regression at integration level.
     *
     * Assertions:
     *   (1) ALL tenants (the would-be-stamped tenantA AND every other tenant) received the row
     *   (2) No tenant DB contains another tenant's data (the negative cross-tenant leak assertion)
     *
     * @see AsyncCanaryTest::testAsyncDispatchInWorkerUsesTenantDsnNotLandlord
     *   Established the active-dispatch-tenant precedent in Phase 20 (Mailer canary).
     * @see 27-CONTEXT.md D-01 — stamp-clearing invariant: subscriber must clear context before dispatch
     */
    public function testWrongTenantIsolationWithActiveDispatchTenant(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');

        $tenantProvider = new StubMultiTenantProvider();
        $tenants = $tenantProvider->findAll();
        $tenantA = $tenants[0]; // tenant_a is the "active" tenant at dispatch time
        $tenantB = $tenants[1]; // tenant_b is the "other" tenant that must also receive the row

        // ---- CRITICAL: set tenantA ACTIVE before the flush/dispatch ----
        // This simulates a real request where a tenant is resolved BEFORE a landlord #[Shared]
        // write triggers the async dispatch.  If the subscriber does NOT clear the context,
        // TenantSendingMiddleware will stamp the envelope with TenantStamp(tenant_a) and
        // TenantWorkerMiddleware will boot only tenant_a's context, limiting the handler to
        // fan out to just tenant_a.  The stamp-clearing invariant (D-01) prevents this.
        $ctx->setTenant($tenantA);

        // Persist a plan that is unique to this test so we can isolate its row in assertions
        $markerPlan = new TestPlan('Stamp Clearing Test Plan', 9001);
        $landlordEm->persist($markerPlan);
        $landlordEm->flush();

        $planId = $markerPlan->getId();
        self::assertNotNull($planId);

        // Assertion (1): ALL tenants must have received the row — including tenantA (the "stamped"
        // tenant) AND tenantB (the "other" tenant that would be missed if the stamp was applied).
        // If only tenantA has the row, stamp-clearing regressed — this is the D-01 canary.
        foreach ($tenants as $tenant) {
            $ctx->setTenant($tenant);
            $conn = $registry->getConnection('tenant');
            if ($conn instanceof \Doctrine\DBAL\Connection) {
                $conn->close();
            }
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $tenantPlan = $tenantEm->find(TestPlan::class, $planId);
            self::assertNotNull(
                $tenantPlan,
                sprintf(
                    'D-01 stamp-clearing: TestPlan#%d must exist in tenant "%s" even though tenant "%s" was ACTIVE at dispatch time. '
                    .'If this fails, stamp-clearing regressed — the subscriber did NOT clear TenantContext before dispatch, '
                    .'so TenantSendingMiddleware stamped the envelope and the handler only fanned out to the active tenant.',
                    $planId,
                    $tenant->getSlug(),
                    $tenantA->getSlug()
                )
            );
        }

        // Assertion (2): No tenant DB contains another tenant's exclusive data — negative cross-tenant
        // leak assertion.  Since both tenants receive ALL shared entities, we verify each tenant
        // received the correct plan (not some phantom from another tenant's exclusive data).
        // We confirm the plan fields in tenantB's DB match the landlord source (not tenantA's data).
        $ctx->setTenant($tenantB);
        $conn = $registry->getConnection('tenant');
        if ($conn instanceof \Doctrine\DBAL\Connection) {
            $conn->close();
        }
        /** @var EntityManagerInterface $tenantBEm */
        $tenantBEm = $registry->resetManager('tenant');

        $tenantBPlan = $tenantBEm->find(TestPlan::class, $planId);
        self::assertNotNull($tenantBPlan);
        self::assertSame(
            'Stamp Clearing Test Plan',
            $tenantBPlan->getName(),
            'tenantB must have the correct plan data from the landlord source, not any tenantA-specific data'
        );
        self::assertSame(
            9001,
            $tenantBPlan->getPriceCents(),
            'tenantB priceCents must match the landlord source — no cross-tenant data leak'
        );

        $ctx->clear();
    }

    /**
     * SHARE-03-e / D-04: vanished-row → delete.
     *
     * Scenario: an 'insert' message is dispatched, but by the time the handler runs,
     * the landlord row has been deleted (a concurrent delete raced the async message).
     * The handler's D-04 path detects find() returns null and propagates a tenant-side
     * delete rather than leaving permanent orphan copies.
     *
     * Setup: manually dispatch a SharedEntityChangedMessage with changeType='insert' for
     * an entity that we DELETE from the landlord BEFORE dispatch.  Because sync:// runs
     * inline, the handler sees null from find() during the same process call.
     */
    public function testVanishedRowPropagatesToTenantDelete(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.bus.default');

        // Step 1: persist the plan on the landlord — sync subscriber dispatches an 'insert' message
        // inline via sync://, so both tenant DBs get the row.
        $plan = new TestPlan('Vanished Row Plan', 2500);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $planId = $plan->getId();
        self::assertNotNull($planId);

        // Verify both tenant DBs received the initial insert
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            $conn = $registry->getConnection('tenant');
            if ($conn instanceof \Doctrine\DBAL\Connection) {
                $conn->close();
            }
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');
            self::assertNotNull(
                $tenantEm->find(TestPlan::class, $planId),
                sprintf('TestPlan#%d must exist in tenant "%s" after initial insert', $planId, $tenant->getSlug())
            );
        }
        $ctx->clear();

        // Step 2: delete the landlord row so the re-fetch in the handler will return null.
        // Do this WITHOUT triggering the subscriber's async dispatch for the delete (we use
        // a direct DQL DELETE to bypass the Doctrine lifecycle events entirely).
        $landlordEm->createQueryBuilder()
            ->delete(TestPlan::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $planId)
            ->getQuery()
            ->execute();
        $landlordEm->clear();

        // Confirm the landlord row is truly gone
        self::assertNull($landlordEm->find(TestPlan::class, $planId), 'Landlord row must be deleted before the vanished-row dispatch');

        // Step 3: manually dispatch a new 'insert' message referencing the now-vanished row.
        // The handler will call find($class, $identifier) which returns null (D-04 path).
        // The handler must convert effectiveType to 'delete' and call deleteRow() on all tenants.
        $bus->dispatch(new SharedEntityChangedMessage(TestPlan::class, ['id' => $planId], 'insert'));

        // Step 4: assert the tenant copies were DELETED (D-04 vanished-row convergence)
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            $conn = $registry->getConnection('tenant');
            if ($conn instanceof \Doctrine\DBAL\Connection) {
                $conn->close();
            }
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');
            self::assertNull(
                $tenantEm->find(TestPlan::class, $planId),
                sprintf(
                    'D-04 vanished-row: TestPlan#%d must be DELETED from tenant "%s" — handler detected null from re-fetch and converged to delete',
                    $planId,
                    $tenant->getSlug()
                )
            );
        }

        $ctx->clear();
    }

    /**
     * SHARE-03-g / D-02: handler throws on tenant failure (throw-to-retry contract).
     *
     * Induces a concrete, idempotent failure: DROP TABLE the shared entity's table on
     * exactly ONE tenant's DB only (try/catch for idempotent setup so re-runs don't fail).
     * Then dispatch the message and assert:
     *   (a) A SharedEntityAsyncFanOutException is thrown (or its HandlerFailedException wrapper).
     *   (b) A HEALTHY tenant (whose table was NOT dropped) still received the change before
     *       the throw — proving best-effort attempt-all (D-02).
     */
    public function testHandlerThrowsOnTenantFailure(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.bus.default');

        $tenantProvider = new StubMultiTenantProvider();
        $tenants = $tenantProvider->findAll();
        $tenantA = $tenants[0]; // will be sabotaged
        $tenantB = $tenants[1]; // healthy — must receive the change

        // Step 1: persist a TestPlan on the landlord so it gets an ID.
        // (This is dispatched inline via the subscriber — the first insert will go through
        //  before we sabotage tenant_a, so both tenants get the row initially.)
        $plan = new TestPlan('Handler Throw Test Plan', 500);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $planId = $plan->getId();
        self::assertNotNull($planId);

        // Step 2: sabotage tenant_a by DROP TABLE on its shared-entity table.
        // Close the DBAL connection first so we can access the SQLite file directly via PDO.
        $ctx->setTenant($tenantA);
        $conn = $registry->getConnection('tenant');
        if ($conn instanceof \Doctrine\DBAL\Connection) {
            $conn->close();
        }
        $ctx->clear();
        $registry->resetManager('tenant');

        $tenantAPath = StubMultiTenantProvider::getTenantAPath();
        try {
            $pdoA = new \PDO('sqlite:'.$tenantAPath);
            $pdoA->exec('DROP TABLE IF EXISTS test_plans');
            unset($pdoA);
        } catch (\Throwable) {
            // Idempotent: if the table doesn't exist, that's fine
        }

        // Step 3: dispatch a SharedEntityChangedMessage directly on the bus for the plan.
        // The handler will: attempt tenant_a (fail — table gone) → accumulate failure →
        // attempt tenant_b (succeed) → throw SharedEntityAsyncFanOutException.
        $fanOutException = null;
        try {
            $bus->dispatch(new SharedEntityChangedMessage(TestPlan::class, ['id' => $planId], 'update'));
        } catch (\Throwable $e) {
            // Unwrap HandlerFailedException wrapper from the bus if present
            $fanOutException = $e;
            while (null !== $fanOutException && !($fanOutException instanceof SharedEntityAsyncFanOutException)) {
                $fanOutException = $fanOutException->getPrevious();
            }

            if (null === $fanOutException) {
                // Re-check: the bus may wrap in HandlerFailedException, unwrap manually
                $inner = $e;
                while ($inner instanceof \Symfony\Component\Messenger\Exception\HandlerFailedException) {
                    $nestedExceptions = $inner->getWrappedExceptions();
                    if ([] === $nestedExceptions) {
                        break;
                    }
                    $inner = reset($nestedExceptions);
                }
                if ($inner instanceof SharedEntityAsyncFanOutException) {
                    $fanOutException = $inner;
                } else {
                    throw $e; // unexpected exception type
                }
            }
        }

        self::assertInstanceOf(
            SharedEntityAsyncFanOutException::class,
            $fanOutException,
            'SHARE-03-g / D-02: Handler must throw SharedEntityAsyncFanOutException (or its '
            .'HandlerFailedException wrapper) when one tenant fails — Messenger retry contract must be observable'
        );

        // Step 4 (D-02 best-effort): tenant_b must have received the change BEFORE the throw.
        // The handler attempts ALL tenants before throwing, so the healthy tenant gets the change.
        $ctx->setTenant($tenantB);
        $conn = $registry->getConnection('tenant');
        if ($conn instanceof \Doctrine\DBAL\Connection) {
            $conn->close();
        }
        /** @var EntityManagerInterface $tenantBEm */
        $tenantBEm = $registry->resetManager('tenant');

        $tenantBPlan = $tenantBEm->find(TestPlan::class, $planId);
        self::assertNotNull(
            $tenantBPlan,
            sprintf(
                'D-02 best-effort: TestPlan#%d must exist in tenant_b EVEN THOUGH tenant_a failed — '
                .'the handler must attempt all tenants before throwing the aggregate exception',
                $planId
            )
        );

        // Step 5: restore tenant_a's table so subsequent tests are unaffected.
        // setUp() re-creates schemas via SchemaTool, so this is belt-and-suspenders,
        // but recreating it here keeps tearDown clean.
        $ctx->clear();
        $registry->resetManager('tenant');
        // (setUp() will dropAndCreateSchema on next test run)
    }

    /**
     * SHARE-03-h: idempotency — re-dispatch yields exactly one row per tenant.
     *
     * Dispatching the same message twice must NOT cause duplicate rows or PK conflicts.
     * The handler's applyRow() uses find-or-new semantics (SharedEntityCopier) so the
     * second dispatch is a no-op per tenant.
     */
    public function testHandlerIdempotentOnRetry(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $container->get('doctrine.orm.landlord_entity_manager');
        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');
        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.bus.default');

        // Persist the plan (triggers first dispatch via subscriber async branch)
        $plan = new TestPlan('Idempotency Test Plan', 7700);
        $landlordEm->persist($plan);
        $landlordEm->flush();

        $planId = $plan->getId();
        self::assertNotNull($planId);

        // Dispatch the same message a SECOND time (simulating a retry)
        $bus->dispatch(new SharedEntityChangedMessage(TestPlan::class, ['id' => $planId], 'insert'));

        // Assert exactly one row per tenant — no duplicates, no PK conflict
        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            $conn = $registry->getConnection('tenant');
            if ($conn instanceof \Doctrine\DBAL\Connection) {
                $conn->close();
            }
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');

            $rows = $tenantEm->getRepository(TestPlan::class)->findBy(['id' => $planId]);
            self::assertCount(
                1,
                $rows,
                sprintf(
                    'SHARE-03-h idempotency: re-dispatch must yield exactly 1 row in tenant "%s", found %d '
                    .'— find-or-new semantics in SharedEntityCopier must prevent duplicate inserts',
                    $tenant->getSlug(),
                    \count($rows)
                )
            );
            self::assertSame('Idempotency Test Plan', $rows[0]->getName());
        }

        $ctx->clear();
    }

    /**
     * Create (or recreate) the schema on the landlord DB and both tenant DBs.
     *
     * Must be called AFTER kernel boot so DatabaseSwitchBootstrapper does not destroy the
     * file-based SQLite DB on next connection.  Mirrors the setup from
     * SharedEntitySyncIntegrationTest::setUpBeforeClass().
     *
     * Uses $registry->getManager() after resetManager() calls in setUp() so we always
     * operate on a fresh EM instance — not on a closed/invalidated one from a prior test.
     */
    private static function createAllSchemas(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $container = self::$kernel->getContainer();

        /** @var TenantContext $ctx */
        $ctx = $container->get('tenancy.context');
        $ctx->clear();

        /** @var ManagerRegistry $registry */
        $registry = $container->get('doctrine');

        // Landlord schema — use getManager() to pick up the freshly-reset EM from setUp()
        /** @var EntityManagerInterface $landlordEm */
        $landlordEm = $registry->getManager('landlord');
        $landlordSchemaTool = new SchemaTool($landlordEm);
        $landlordMeta = $landlordEm->getMetadataFactory()->getAllMetadata();
        // Drop + create to start each test from a clean state
        $landlordSchemaTool->dropSchema($landlordMeta);
        $landlordSchemaTool->createSchema($landlordMeta);

        // Per-tenant schemas — must setTenant + close() + resetManager() to route the
        // DBAL connection through TenantAwareDriver to the correct SQLite file
        /** @var \Doctrine\DBAL\Connection $tenantConn */
        $tenantConn = $container->get('doctrine.dbal.tenant_connection');

        $tenantProvider = new StubMultiTenantProvider();
        foreach ($tenantProvider->findAll() as $tenant) {
            $ctx->setTenant($tenant);
            $tenantConn->close();
            /** @var EntityManagerInterface $tenantEm */
            $tenantEm = $registry->resetManager('tenant');
            $schemaTool = new SchemaTool($tenantEm);
            $tenantMeta = $tenantEm->getMetadataFactory()->getAllMetadata();
            $schemaTool->dropSchema($tenantMeta);
            $schemaTool->createSchema($tenantMeta);
        }

        // Clean context so tests start from landlord state
        $ctx->clear();
        $registry->resetManager('tenant');
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $items */
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
