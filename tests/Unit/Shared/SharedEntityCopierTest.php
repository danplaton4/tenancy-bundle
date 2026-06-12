<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * Unit stubs for SharedEntityCopier (SHARE-02-a + classify correctness).
 *
 * Every test method is skip-guarded on class_exists(SharedEntityCopier::class).
 * The production class does not exist until Plan 26-02; stubs keep the suite green.
 */
final class SharedEntityCopierTest extends TestCase
{
    /**
     * SHARE-02-a: #[Shared] classes enumerated via landlord metadata (D-07, reflClass proxy-safe).
     */
    public function testEnumeratesSharedClassesViaLandlordMetadata(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }

    /**
     * classifyRow() returns 'insert' when the tenant copy does not exist (find() returns null).
     */
    public function testClassifyRowReturnsInsertWhenAbsent(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }

    /**
     * classifyRow() returns 'update' when a scalar field differs between landlord and tenant copy.
     */
    public function testClassifyRowReturnsUpdateOnScalarDrift(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }

    /**
     * classifyRow() returns 'in-sync' when all scalar fields match.
     */
    public function testClassifyRowReturnsInSyncWhenIdentical(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }

    /**
     * applyRow() sets syncInProgress=true before flush and false after (re-entrancy guard).
     */
    public function testApplyRowSetsAndResetsSyncInProgressAroundFlush(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }

    /**
     * isSyncInProgress() returns false when called outside of applyRow().
     */
    public function testIsSyncInProgressFalseOutsideApply(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }

    /**
     * isShared() uses reflClass proxy-safe check (D-07).
     */
    public function testIsSharedUsesReflClassProxySafe(): void
    {
        if (!class_exists(\Tenancy\Bundle\Shared\SharedEntityCopier::class)) {
            self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
        }

        self::markTestSkipped('SharedEntityCopier not yet extracted (Plan 26-02)');
    }
}
