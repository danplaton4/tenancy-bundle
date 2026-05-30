<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub integration test for the Phase 24 long-running-worker LRU simulation
 * (100 tenants × N filesystem operations → bounded cache → no leaks).
 *
 * Filled in by Plan 24-08.
 *
 * Mirrors the Phase 20 LongRunningWorkerSimulationTest shape — same cache
 * eviction invariant, same belt-and-suspenders flush on TenantContextCleared.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (LruFilesystemCache mirrors LruTransportCache; clear() is the long-worker
 * resource-exhaustion guard).
 */
final class LongRunningWorkerFilesystemSimulationTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-08. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
