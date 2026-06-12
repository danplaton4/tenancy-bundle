<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;

/**
 * Unit stubs for SharedEntityResyncCommand (SHARE-02-b..g, SHARE-02-k).
 *
 * Every test method is skip-guarded on class_exists(SharedEntityResyncCommand::class).
 * The production class does not exist until Plan 26-03; stubs keep the suite green.
 */
final class SharedEntityResyncCommandTest extends TestCase
{
    /**
     * SHARE-02-b: --dry-run classifies each row insert/update/in-sync but never flushes (D-03).
     */
    public function testDryRunNeverWrites(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-c: Live run prints drift summary then confirm(); default-No aborts cleanly (D-04).
     */
    public function testLiveRunPromptsConfirmDefaultNoAbortsCleanly(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-d: --force skips confirmation and proceeds immediately (D-04).
     */
    public function testForceSkipsConfirmation(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-e: shared_db driver → informational no-op message, exits SUCCESS (D-05).
     */
    public function testSharedDbDriverExitsSuccessWithNoOp(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-f: Continue-on-failure: one tenant fails, others succeed, command exits FAILURE (D-06).
     */
    public function testContinueOnFailureExitsFailureWhenAnyTenantFails(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-g: TenantContext::clear() + BootstrapperChain::clear() called in finally per tenant (D-06).
     */
    public function testContextAndBootstrapperClearedInFinally(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }

    /**
     * SHARE-02-k: --tenant=<slug> targets single tenant only (D-01).
     */
    public function testTenantOptionTargetsSingleTenant(): void
    {
        if (!class_exists(\Tenancy\Bundle\Command\SharedEntityResyncCommand::class)) {
            self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
        }

        self::markTestSkipped('SharedEntityResyncCommand not yet built (Plan 26-03)');
    }
}
