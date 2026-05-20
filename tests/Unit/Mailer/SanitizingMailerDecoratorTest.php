<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerInvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Tenancy\Bundle\Exception\TenantSanitizedTransportException;
use Tenancy\Bundle\Mailer\SanitizingMailerDecorator;

/**
 * @see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-e
 */
final class SanitizingMailerDecoratorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TransportException::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }
    }

    public function testSuccessfulSendIsDelegatedTransparently(): void
    {
        $message = new RawMessage('payload');
        $envelope = null;

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())
            ->method('send')
            ->with($message, $envelope);

        $decorator = new SanitizingMailerDecorator($inner);
        $decorator->send($message, $envelope);
    }

    public function testTransportExceptionMessageIsRedacted(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(
            new TransportException('Unable to connect to "smtp://user:hunter2@host:25"'),
        );

        $decorator = new SanitizingMailerDecorator($inner);

        try {
            $decorator->send(new RawMessage('x'));
            self::fail('Expected TenantSanitizedTransportException');
        } catch (TenantSanitizedTransportException $caught) {
            self::assertStringContainsString('smtp://user:***@host:25', $caught->getMessage());
            self::assertStringNotContainsString('hunter2', $caught->getMessage());
            self::assertInstanceOf(TransportException::class, $caught);
        }
    }

    public function testRethrowPreservesCodeAndPrevious(): void
    {
        $original = new TransportException('connect smtp://u:p@h', 42);
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException($original);

        $decorator = new SanitizingMailerDecorator($inner);

        try {
            $decorator->send(new RawMessage('x'));
            self::fail('Expected TenantSanitizedTransportException');
        } catch (TenantSanitizedTransportException $caught) {
            self::assertSame(42, $caught->getCode());
            self::assertSame($original, $caught->getPrevious());
        }
    }

    public function testMessageWithoutDsnIsStillRewrappedUnchanged(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(new TransportException('connection refused'));

        $decorator = new SanitizingMailerDecorator($inner);

        try {
            $decorator->send(new RawMessage('x'));
            self::fail('Expected TenantSanitizedTransportException');
        } catch (TenantSanitizedTransportException $caught) {
            self::assertSame('connection refused', $caught->getMessage());
        }
    }

    public function testNonTransportExceptionPropagatesAsIs(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(new \RuntimeException('boom'));

        $decorator = new SanitizingMailerDecorator($inner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $decorator->send(new RawMessage('x'));
    }

    public function testSendSignatureMatchesMailerInterface(): void
    {
        $reflection = new \ReflectionMethod(SanitizingMailerDecorator::class, 'send');

        self::assertSame('send', $reflection->getName());
        self::assertSame('void', (string) $reflection->getReturnType());

        $params = $reflection->getParameters();
        self::assertCount(2, $params);
        self::assertSame(RawMessage::class, (string) $params[0]->getType());
        self::assertSame('?'.Envelope::class, (string) $params[1]->getType());
        self::assertTrue($params[1]->isDefaultValueAvailable());
        self::assertNull($params[1]->getDefaultValue());
    }

    /**
     * Plan 20-10 / REVIEW WR-01 — UnsupportedSchemeException's message
     * may carry the DSN (when bridge factories report "no transport
     * supports scheme 'X' for dsn 'smtp://user:secret@host'"). Must be
     * caught and redacted, re-thrown as \RuntimeException (NOT as a
     * TenantSanitizedTransportException — UnsupportedSchemeException is
     * not a TransportException subtype, so the type contract for the
     * TenantSanitizedTransportException class would change).
     */
    public function testUnsupportedSchemeExceptionMessageIsRedacted(): void
    {
        if (!class_exists(UnsupportedSchemeException::class)) {
            self::markTestSkipped('UnsupportedSchemeException not in this symfony/mailer version');
        }
        $inner = $this->createMock(MailerInterface::class);
        // UnsupportedSchemeException's real constructor takes (Dsn $dsn, ?string $name, array $supported)
        // and derives the message from the DSN scheme — it cannot directly carry a DSN
        // string in its message. We use an inline anonymous subclass that re-routes
        // the constructor to \Exception::__construct(string) so we can supply an
        // arbitrary message containing a DSN, simulating a future bridge that puts
        // the DSN in the message.
        $inner->method('send')->willThrowException(new class('cannot use smtp://user:hunter2@host scheme') extends UnsupportedSchemeException {
            public function __construct(string $message)
            {
                \Exception::__construct($message);
            }
        });

        $decorator = new SanitizingMailerDecorator($inner);

        try {
            $decorator->send(new RawMessage('x'));
            self::fail('Expected \RuntimeException');
        } catch (\RuntimeException $caught) {
            // NOT a TenantSanitizedTransportException — it's an UnsupportedScheme,
            // not a TransportException, so we use \RuntimeException to avoid
            // promoting it to TransportException type.
            self::assertNotInstanceOf(TenantSanitizedTransportException::class, $caught);
            self::assertStringContainsString('smtp://user:***@host', $caught->getMessage());
            self::assertStringNotContainsString('hunter2', $caught->getMessage());
        }
    }

    /**
     * Plan 20-10 / REVIEW WR-01 — Mailer's InvalidArgumentException
     * (distinct from \InvalidArgumentException) also caught and redacted.
     */
    public function testMailerInvalidArgumentExceptionMessageIsRedacted(): void
    {
        if (!class_exists(MailerInvalidArgumentException::class)) {
            self::markTestSkipped('Mailer\\InvalidArgumentException not in this symfony/mailer version');
        }
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(
            new MailerInvalidArgumentException('bad config for smtp://user:hunter2@host'),
        );

        $decorator = new SanitizingMailerDecorator($inner);

        try {
            $decorator->send(new RawMessage('x'));
            self::fail('Expected \RuntimeException');
        } catch (\RuntimeException $caught) {
            self::assertNotInstanceOf(TenantSanitizedTransportException::class, $caught);
            self::assertStringContainsString('smtp://user:***@host', $caught->getMessage());
            self::assertStringNotContainsString('hunter2', $caught->getMessage());
        }
    }

    /**
     * Plan 20-10 / WR-01 — global \RuntimeException (NOT a Mailer
     * exception) continues to propagate as-is (this is the existing
     * testNonTransportExceptionPropagatesAsIs contract — re-verified
     * post-widening to prove the new catch arm doesn't over-catch).
     */
    public function testGlobalRuntimeExceptionStillPropagatesUnchanged(): void
    {
        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(new \RuntimeException('non-mailer boom'));

        $decorator = new SanitizingMailerDecorator($inner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-mailer boom');
        $decorator->send(new RawMessage('x'));
    }
}
