<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Mailer\DsnSanitizer;

/**
 * @see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-e-helper
 */
final class DsnSanitizerTest extends TestCase
{
    public function testRedactsSmtpUserPasswordPair(): void
    {
        self::assertSame(
            'smtp://user:***@host:25',
            DsnSanitizer::redact('smtp://user:secret@host:25'),
        );
    }

    public function testRedactsComplexPasswordWithUrlSpecialChars(): void
    {
        self::assertSame(
            'smtps://u:***@host',
            DsnSanitizer::redact('smtps://u:complex+pass-w!th@host'),
        );
    }

    public function testRedactsSendmailDsnWithPathAfterAt(): void
    {
        self::assertSame(
            'sendmail://user:***@/usr/sbin/sendmail',
            DsnSanitizer::redact('sendmail://user:pwd@/usr/sbin/sendmail'),
        );
    }

    public function testLeavesDsnWithoutCredentialsUnchanged(): void
    {
        self::assertSame(
            'smtp://host:25',
            DsnSanitizer::redact('smtp://host:25'),
        );
    }

    public function testNullPassthrough(): void
    {
        self::assertNull(DsnSanitizer::redact(null));
    }

    public function testEmptyStringPassthrough(): void
    {
        self::assertSame('', DsnSanitizer::redact(''));
    }

    public function testNonDsnStringIsUnchanged(): void
    {
        self::assertSame(
            'not a dsn at all',
            DsnSanitizer::redact('not a dsn at all'),
        );
    }

    /**
     * Plan 20-10 / REVIEW WR-07 — failover composite DSNs must have
     * every password redacted, not just the first.
     */
    public function testFailoverCompositeRedactsAllPasswords(): void
    {
        $input = 'failover(smtp://u1:secret1@h1 smtp://u2:secret2@h2)';
        $result = DsnSanitizer::redact($input);

        self::assertStringContainsString('smtp://u1:***@h1', $result);
        self::assertStringContainsString('smtp://u2:***@h2', $result);
        self::assertStringNotContainsString('secret1', $result);
        self::assertStringNotContainsString('secret2', $result);
    }

    /**
     * Plan 20-10 / REVIEW WR-07 — free-text containing `:` and `@` must
     * NOT be mangled. Previously the regex `[\/]{0,2}` allowed zero
     * slashes, matching arbitrary "user:host" / port:host shapes.
     */
    public function testDoesNotMangleFreeTextColons(): void
    {
        self::assertSame(
            'Could not deliver to user@example.com via smtp:587 timeout',
            DsnSanitizer::redact('Could not deliver to user@example.com via smtp:587 timeout'),
        );
    }

    /**
     * Plan 20-10 — round-trip sanity: a DSN already containing `***` in
     * the password slot is a fixed point (DsnSanitizer is idempotent).
     */
    public function testIdempotentOnAlreadyRedactedDsn(): void
    {
        self::assertSame(
            'smtp://user:***@host:25',
            DsnSanitizer::redact('smtp://user:***@host:25'),
        );
    }
}
