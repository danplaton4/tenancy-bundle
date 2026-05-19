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
}
