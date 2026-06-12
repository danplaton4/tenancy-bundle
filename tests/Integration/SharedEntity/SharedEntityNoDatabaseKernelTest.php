<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity;

// SharedEntityNoDatabaseKernelTest uses driver: shared_db with
// database.enabled left at its default (false).
//
// WR-05: under this configuration the SharedEntitySyncSubscriber is NEVER registered —
// TenancyBundle::loadExtension() wires it only inside the `if (database.enabled)` block,
// and the config validator forbids `shared_db` + `database.enabled: true` outright. So the
// no-op here is STRUCTURAL (the service does not exist), NOT the result of the runtime
// `'shared_db' === $driver` short-circuit in postFlush() running. This test therefore asserts
// that structural reality explicitly (the service is absent) rather than implying the runtime
// branch was exercised. See SHARE-01-j.
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\Entity\TestPlan;
use Tenancy\Bundle\Tests\Integration\SharedEntity\Support\SharedEntityNoDbTestKernel;

/**
 * Covers SHARE-01-j: subscriber is a no-op under the shared_db driver.
 *
 * When driver = shared_db there are no per-tenant EMs to fan out to.
 * The SharedEntitySyncSubscriber must short-circuit without attempting any
 * fan-out, and no exception or log warning should fire.
 *
 * The kernel uses driver: shared_db with a single default EM (no landlord/tenant split).
 */
final class SharedEntityNoDatabaseKernelTest extends TestCase
{
    private static ?SharedEntityNoDbTestKernel $kernel = null;
    private static string $dbPath;

    public static function setUpBeforeClass(): void
    {
        self::$dbPath = sys_get_temp_dir().'/tenancy_shared_entity_nodb_test.db';
        @unlink(self::$dbPath);

        self::$kernel = new SharedEntityNoDbTestKernel();
        self::$kernel->boot();

        $container = self::$kernel->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        @unlink(self::$dbPath);
    }

    /**
     * SHARE-01-j: Subscriber is a STRUCTURAL no-op under the shared_db driver.
     *
     * WR-05: under `driver: shared_db` (with database.enabled false) the
     * SharedEntitySyncSubscriber service is never registered — see the file-level comment. The
     * no-op is therefore guaranteed by DI wiring, not by the runtime `'shared_db' === $driver`
     * short-circuit in postFlush(). This test proves that: it asserts the service is absent, then
     * asserts the single shared row exists exactly once in the default EM (no phantom fan-out).
     *
     * The runtime short-circuit in postFlush() is consequently dead code under the only
     * configuration that reaches this kernel; it remains as defence-in-depth for any future
     * wiring change, but is not exercised here because the service cannot exist here.
     */
    public function testNoOpUnderSharedDb(): void
    {
        $container = self::$kernel->getContainer();

        // WR-05: prove the no-op is structural — the subscriber service is NOT wired under
        // shared_db, so there is nothing that could fan out. This is the actual mechanism behind
        // SHARE-01-j, made explicit instead of relying on "one row exists" passing trivially.
        $this->assertFalse(
            $container->has('tenancy.shared_entity_sync_subscriber'),
            'Under driver: shared_db the SharedEntitySyncSubscriber must NOT be registered — '
            .'the no-op is structural (DI), not a runtime short-circuit.'
        );

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.default_entity_manager');

        // Persist a #[Shared] entity in the shared_db context — no fan-out should occur,
        // no exception should throw, and the row exists exactly once in the single DB.
        $noException = true;
        $plan = null;
        try {
            $plan = new TestPlan('Shared DB No-Op Plan', 5000);
            $em->persist($plan);
            $em->flush();
        } catch (\Throwable $e) {
            $noException = false;
        }

        $this->assertTrue($noException, 'Persisting a #[Shared] entity under shared_db driver must not throw');
        $this->assertNotNull($plan, 'TestPlan instance must be created');
        $this->assertNotNull($plan->getId(), 'TestPlan must have an ID after flush');

        // Verify the row exists exactly once in the shared DB (no duplication from phantom fan-out)
        $em->clear();
        $found = $em->find(TestPlan::class, $plan->getId());
        $this->assertNotNull($found, 'TestPlan must exist in the shared DB');
        $this->assertSame('Shared DB No-Op Plan', $found->getName());

        // Verify there are no extra rows (fan-out would create duplicate rows under shared_db)
        $count = (int) $em->createQuery('SELECT COUNT(p.id) FROM '.TestPlan::class.' p')
            ->getSingleScalarResult();

        // Under shared_db there is exactly one row for each insert (no fan-out duplication)
        $this->assertSame(1, $count, 'Under shared_db driver, exactly one row should exist (no phantom fan-out duplication)');
    }
}
