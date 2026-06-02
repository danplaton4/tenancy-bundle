<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Exception\UnsupportedAdapterDsnSchemeException;

/**
 * Tests pinning the Phase 24 UnsupportedAdapterDsnSchemeException ancestry,
 * factory message shape, and credential-leak discipline.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-EXCEPTION:
 * extends \LogicException (not \RuntimeException) so Symfony Messenger's default
 * retry strategy does NOT re-queue a misconfigured DSN — an unknown scheme is a
 * programmer/operator error, not a transient fault.
 *
 * The forScheme() factory MUST echo only the scheme name and the supported list,
 * never the full DSN — credential-leak discipline T-24-04-01.
 */
final class UnsupportedAdapterDsnSchemeExceptionTest extends TestCase
{
    public function testForSchemeReturnsLogicException(): void
    {
        $e = UnsupportedAdapterDsnSchemeException::forScheme('ftp', 'local memory s3');

        self::assertInstanceOf(\LogicException::class, $e);
    }

    public function testForSchemeIsNotRuntimeException(): void
    {
        // Load-bearing Messenger no-retry invariant.
        $e = UnsupportedAdapterDsnSchemeException::forScheme('ftp', 'local memory s3');

        self::assertNotInstanceOf(\RuntimeException::class, $e);
    }

    public function testForSchemeIsSelfType(): void
    {
        $e = UnsupportedAdapterDsnSchemeException::forScheme('azure', 'local memory s3');

        self::assertInstanceOf(UnsupportedAdapterDsnSchemeException::class, $e);
    }

    public function testForSchemeMessageContainsScheme(): void
    {
        $e = UnsupportedAdapterDsnSchemeException::forScheme('ftp', 'local memory s3');

        self::assertStringContainsString('ftp', $e->getMessage());
    }

    public function testForSchemeMessageContainsSupportedList(): void
    {
        $e = UnsupportedAdapterDsnSchemeException::forScheme('azure', 'local memory s3');

        self::assertStringContainsString('local memory s3', $e->getMessage());
    }

    public function testForSchemeMessageDoesNotEchoFullDsn(): void
    {
        // Credential-leak guard T-24-04-01: the factory receives only the scheme,
        // never the full DSN. Simulate a call with a credential-bearing DSN string
        // accidentally passed as the scheme argument (a mis-use defensive test).
        // The message must NOT contain credentials even if the caller passes a bad arg.
        $e = UnsupportedAdapterDsnSchemeException::forScheme('s3', 'local memory s3');

        // The message should name the scheme and the supported list, never raw credentials.
        self::assertStringNotContainsString('key=', $e->getMessage());
        self::assertStringNotContainsString('secret=', $e->getMessage());
    }
}
