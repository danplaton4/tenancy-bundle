<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 FilesystemPrefixingDecorator (prefix-mode adapter).
 *
 * Filled in by Plan 24-05.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * (prefix mode is the default isolation strategy — single shared adapter,
 * tenant_<slug>/ prefix applied at the FilesystemOperator boundary).
 */
final class FilesystemPrefixingDecoratorTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-05. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
