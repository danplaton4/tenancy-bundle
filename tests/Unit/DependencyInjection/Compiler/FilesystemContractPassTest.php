<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 FilesystemContractPass (three compile-time guards
 * + tag-driven decorator wiring).
 *
 * Filled in by Plan 24-07.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-COMPILE-PASS
 * — guards: (1) reject filesystem.enabled=true without league/flysystem-bundle,
 * (2) reject per_tenant_adapter strategy under allow_per_tenant_adapter=false,
 * (3) verify every tagged service has a valid strategy attribute.
 */
final class FilesystemContractPassTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-07. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
