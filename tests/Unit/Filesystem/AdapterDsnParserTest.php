<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 AdapterDsnParser (DSN → FilesystemAdapter factory).
 *
 * Filled in by Plan 24-04. Covers three v0.4 schemes — local://, memory://,
 * s3:// — plus the unknown-scheme failure mode (UnsupportedAdapterDsnSchemeException
 * extends \LogicException, mirroring DEC-FILE-EXCEPTION).
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (per_tenant_adapter mode requires a DSN parser; Flysystem 3 does not ship one).
 */
final class AdapterDsnParserTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-04. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
