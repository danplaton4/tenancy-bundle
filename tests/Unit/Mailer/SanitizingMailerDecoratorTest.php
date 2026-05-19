<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
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
}
