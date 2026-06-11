<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity;

// Production class SharedEntitySyncSubscriber lands in Plan 25-04.
// SharedEntityNoDatabaseKernelTest uses driver: shared_db — the subscriber
// is a documented no-op under this driver (D-03).
//
// Wave 0 behavior: the test can execute in Wave 0 without the production subscriber —
// asserting "no fan-out occurs" is trivially true when the subscriber does not yet exist.
// Once the subscriber lands in Plan 25-04, the D-03 short-circuit path is what ensures
// the test stays green.
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
     * SHARE-01-j: Subscriber is a no-op under the shared_db driver.
     *
     * Under shared_db, TenantProviderInterface::findAll() must never be called by the
     * sync subscriber. The single shared row exists exactly once in the default EM.
     * No fan-out attempt occurs (D-03 documented no-op).
     *
     * Wave 0: test passes trivially because the subscriber does not yet exist.
     * Wave 4 (Plan 25-04): test validates the D-03 short-circuit path in the production subscriber.
     */
    public function testNoOpUnderSharedDb(): void
    {
        $container = self::$kernel->getContainer();
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
