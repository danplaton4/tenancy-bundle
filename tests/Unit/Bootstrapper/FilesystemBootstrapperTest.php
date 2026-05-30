<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use PHPUnit\Framework\TestCase;

/**
 * Stub test for the Phase 24 FilesystemBootstrapper (no-op boot + LRU clear).
 *
 * Filled in by Plan 24-07.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-PRIORITY
 * (priority -30 on tenancy.bootstrapper tag — boots after Mailer at -20, clears
 * before Doctrine at -10 in reverse order; live-read decorator pattern means
 * boot() itself is a no-op like Phase 20 MailerBootstrapper).
 */
final class FilesystemBootstrapperTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-07. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
