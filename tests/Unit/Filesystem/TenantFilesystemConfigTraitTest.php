<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 TenantFilesystemConfigTrait default implementation.
 *
 * Filled in by Plan 24-01.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-CONFIG
 * (TenantInterface does NOT gain a new abstract method; users opt into the
 * trait OR receive the default via AbstractTenant — zero BC break for v0.3
 * downstreams with custom Tenant entities).
 */
final class TenantFilesystemConfigTraitTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-01. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
