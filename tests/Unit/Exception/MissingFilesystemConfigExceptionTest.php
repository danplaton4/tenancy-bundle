<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Exception\MissingFilesystemConfigException;

/**
 * Tests pinning the Phase 24 MissingFilesystemConfigException ancestry and
 * factory message shape.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-EXCEPTION:
 * extends \LogicException (not \RuntimeException) so Symfony Messenger's default
 * retry strategy does NOT re-queue a misconfigured tenant — misconfiguration is a
 * programmer/operator error, not a transient fault.
 *
 * The forTenant() factory message must name the tenant slug and include "adapter_dsn"
 * so the operator knows exactly what to set.
 */
final class MissingFilesystemConfigExceptionTest extends TestCase
{
    public function testForTenantReturnsLogicException(): void
    {
        $e = MissingFilesystemConfigException::forTenant('acme');

        self::assertInstanceOf(\LogicException::class, $e);
    }

    public function testForTenantIsNotRuntimeException(): void
    {
        // Load-bearing Messenger no-retry invariant: LogicException is NOT a
        // RuntimeException, so Messenger's default retry strategy excludes it.
        $e = MissingFilesystemConfigException::forTenant('acme');

        self::assertNotInstanceOf(\RuntimeException::class, $e);
    }

    public function testForTenantMessageContainsSlug(): void
    {
        $e = MissingFilesystemConfigException::forTenant('acme');

        self::assertStringContainsString('acme', $e->getMessage());
    }

    public function testForTenantMessageContainsAdapterDsnGuidance(): void
    {
        $e = MissingFilesystemConfigException::forTenant('acme');

        self::assertStringContainsString('adapter_dsn', $e->getMessage());
    }

    public function testForTenantIsSelfType(): void
    {
        $e = MissingFilesystemConfigException::forTenant('globex');

        self::assertInstanceOf(MissingFilesystemConfigException::class, $e);
    }
}
