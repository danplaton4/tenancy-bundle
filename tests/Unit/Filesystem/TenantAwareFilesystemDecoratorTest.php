<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 TenantAwareFilesystemDecorator (per-tenant-adapter mode).
 *
 * Filled in by Plan 24-06.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (per_tenant_adapter mode reads tenant filesystemConfig.adapter_dsn and
 * instantiates a per-tenant FilesystemOperator via AdapterDsnParser; cached
 * via LruFilesystemCache).
 */
final class TenantAwareFilesystemDecoratorTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-06. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
