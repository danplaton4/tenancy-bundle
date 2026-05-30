<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 LruFilesystemCache (bounded per-tenant adapter cache).
 *
 * Filled in by Plan 24-03.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (LRU cache prevents unbounded growth in long-running workers — mirrors the
 * Phase 20 src/Mailer/LruTransportCache shape; cleared on TenantContextCleared).
 */
final class LruFilesystemCacheTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-03. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
