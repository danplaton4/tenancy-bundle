<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 TenantContextClearedListener (LRU flush on context-cleared).
 *
 * Filled in by Plan 24-03.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-PRIORITY
 * (filesystem clear() runs in reverse boot order — listener provides the
 * belt-and-suspenders TenantContextCleared hook so the LRU is flushed without
 * waiting for the bootstrapper chain's reverse-order clear()).
 */
final class TenantContextClearedListenerTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-03. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
