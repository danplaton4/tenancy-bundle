<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;

/**
 * Stub test pinning the Phase 24 MissingFilesystemConfigException ancestry.
 *
 * Filled in by Plan 24-02.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-EXCEPTION
 * (extends \LogicException — Messenger's default retry strategy treats
 * RuntimeException as transient; a misconfigured tenant is a programmer error
 * and MUST NOT be retried, so the exception's LogicException ancestry is
 * load-bearing).
 */
final class MissingFilesystemConfigExceptionTest extends TestCase
{
    public function testStub(): void
    {
        $this->markTestIncomplete('Stub — implemented in Plan 24-02. See .planning/phases/24-filesystem-bootstrapper/24-VALIDATION.md.');
    }
}
