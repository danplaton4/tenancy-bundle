<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub integration test for Phase 24 — five end-to-end scenarios listed in
 * CONTEXT §DEC-FILE-TEST-ADAPTER.
 *
 * Filled in by Plan 24-08.
 *
 * Scenarios this class will cover (once implemented):
 *   1. testPrefixModeIsolation — tenant A writes invisible to tenant B reads
 *   2. testPerTenantAdapterIsolation — distinct adapters per tenant adapter_dsn
 *   3. testUntaggedServicesBypassScoping — public.storage stays landlord-shared
 *   4. testMissingFilesystemConfigThrowsLogicException — no-retry pattern
 *   5. testLruCacheClearedOnTenantContextCleared — long-worker safety
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-TEST-ADAPTER.
 */
final class FilesystemBootstrapperIntegrationTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-08. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
