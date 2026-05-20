<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Tenancy\Bundle\Exception\TenantSanitizedTransportException;

/**
 * @see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-e
 */
final class TenantSanitizedTransportExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TransportException::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(TenantSanitizedTransportException::class);
        self::assertTrue($reflection->isFinal(), 'TenantSanitizedTransportException must be final');
    }

    public function testExtendsTransportException(): void
    {
        $exception = new TenantSanitizedTransportException('msg');
        self::assertInstanceOf(TransportException::class, $exception);
    }

    public function testImplementsTransportExceptionInterface(): void
    {
        $exception = new TenantSanitizedTransportException('msg');
        self::assertInstanceOf(TransportExceptionInterface::class, $exception);
    }

    public function testPreservesMessageCodePrevious(): void
    {
        $previous = new \RuntimeException('original');
        $exception = new TenantSanitizedTransportException('msg', 42, $previous);

        self::assertSame('msg', $exception->getMessage());
        self::assertSame(42, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * Plan 20-10 / REVIEW BL-01 — the headline regression test.
     *
     * Previous TransportException has $debug populated with SMTP
     * transcript containing a DSN. Wrapper's getDebug() must return a
     * redacted version (no `:secret@`).
     */
    public function testGetDebugIsRedactedWhenPreviousTransportExceptionHasDebug(): void
    {
        $previous = new TransportException('connection refused');
        $previous->appendDebug('SMTP transcript: connecting smtp://user:hunter2@host:25 — refused');

        $wrapper = new TenantSanitizedTransportException('connection refused', 0, $previous);

        self::assertStringNotContainsString('hunter2', $wrapper->getDebug(),
            'Plan 20-10 BL-01: wrapper getDebug must NOT contain unredacted password');
        self::assertStringContainsString('smtp://user:***@host:25', $wrapper->getDebug());
        // Cause chain preserved for stack-trace diagnostics.
        self::assertSame($previous, $wrapper->getPrevious());
    }

    /**
     * Plan 20-10 / BL-01 — non-TransportException previous leaves the
     * wrapper's debug at its parent-class default of '' (no copy attempt).
     */
    public function testGetDebugIsEmptyStringWhenPreviousIsNotTransportException(): void
    {
        $previous = new \RuntimeException('not a transport error');
        $wrapper = new TenantSanitizedTransportException('boom', 0, $previous);

        self::assertSame('', $wrapper->getDebug());
    }

    /**
     * Plan 20-10 / BL-01 — no previous at all leaves debug empty.
     */
    public function testGetDebugIsEmptyStringWhenNoPrevious(): void
    {
        $wrapper = new TenantSanitizedTransportException('msg');
        self::assertSame('', $wrapper->getDebug());
    }

    /**
     * Plan 20-10 / BL-01 — previous TransportException with empty $debug
     * doesn't trigger appendDebug (no-op branch). Wrapper's getDebug is '' still.
     */
    public function testGetDebugIsEmptyStringWhenPreviousTransportExceptionHasEmptyDebug(): void
    {
        $previous = new TransportException('msg'); // no appendDebug call
        $wrapper = new TenantSanitizedTransportException('msg', 0, $previous);

        self::assertSame('', $wrapper->getDebug());
    }

    /**
     * Plan 20-10 / BL-01 — regex grep over getDebug must NOT find a
     * `:password@` shape with a password between colon and @. This is
     * the load-bearing security assertion.
     */
    public function testGetDebugContainsNoUnredactedPasswordPattern(): void
    {
        $previous = new TransportException('boom');
        $previous->appendDebug('Failure: smtp://user:secret@host & sendmail://user:pwd@/usr/sbin/sendmail');

        $wrapper = new TenantSanitizedTransportException('boom', 0, $previous);

        // Pattern: `://<user>:<not-***>@` — any match means a password leaked.
        self::assertSame(0, preg_match('#://[^:/@]+:(?!\*\*\*@)[^@/]+@#', $wrapper->getDebug()),
            'Plan 20-10 BL-01 invariant: getDebug must contain no `:<password>@` for any non-*** password');
    }
}
