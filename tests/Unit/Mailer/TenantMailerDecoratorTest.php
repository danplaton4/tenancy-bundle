<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Mailer\TenantMailerDecorator;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\StubTenantMailerExtension;

/**
 * Pure-unit coverage for TenantMailerDecorator. Asserts upstream stamping
 * behavior — closes Gap #1 from 20-VERIFICATION.md.
 */
final class TenantMailerDecoratorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(MailerInterface::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }
    }

    private function makeTenant(string $slug, ?string $dsn, ?string $from = null, ?string $replyTo = null): TenantInterface
    {
        $tenant = new class implements TenantInterface {
            use StubTenantMailerExtension;
            private string $slug = '';

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function setSlug(string $slug): void
            {
                $this->slug = $slug;
            }

            public function getDomain(): ?string
            {
                return null;
            }

            public function getConnectionConfig(): array
            {
                return [];
            }

            public function getName(): string
            {
                return $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }
        };
        $tenant->setSlug($slug);
        $tenant->setMailerDsn($dsn);
        if (null !== $from) {
            $tenant->setMailerFrom($from);
        }
        if (null !== $replyTo) {
            $tenant->setMailerReplyTo($replyTo);
        }

        return $tenant;
    }

    public function testStampsXTransportFromActiveTenantOnEmail(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('acme', 'smtp://u:p@h:25'));

        $inner = $this->createMock(MailerInterface::class);
        $captured = null;
        $inner->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (RawMessage $m) use (&$captured): void {
                $captured = $m;
            });

        $decorator = new TenantMailerDecorator($inner, $context);
        $email = (new Email())->to('r@example.com')->subject('s')->text('b');

        $decorator->send($email);

        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('tenant_acme', $captured->getHeaders()->get('X-Transport')->getBodyAsString());
    }

    public function testStampsFromAndReplyToFromActiveTenantOnEmail(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('acme', 'smtp://u:p@h', 'sender@acme.example.com', 'reply@acme.example.com'));

        $inner = $this->createMock(MailerInterface::class);
        $captured = null;
        $inner->method('send')->willReturnCallback(function (RawMessage $m) use (&$captured): void {
            $captured = $m;
        });

        (new TenantMailerDecorator($inner, $context))->send((new Email())->to('r@example.com')->subject('s')->text('b'));

        self::assertInstanceOf(Email::class, $captured);
        self::assertSame('sender@acme.example.com', $captured->getFrom()[0]->getAddress());
        self::assertSame('reply@acme.example.com', $captured->getReplyTo()[0]->getAddress());
    }

    public function testDoesNotStampWhenNoActiveTenant(): void
    {
        $context = new TenantContext(); // no setTenant()
        $inner = $this->createMock(MailerInterface::class);
        $captured = null;
        $inner->method('send')->willReturnCallback(function (RawMessage $m) use (&$captured): void {
            $captured = $m;
        });

        (new TenantMailerDecorator($inner, $context))->send((new Email())->to('r@example.com')->subject('s')->text('b'));

        self::assertInstanceOf(Email::class, $captured);
        self::assertFalse($captured->getHeaders()->has('X-Transport'));
    }

    public function testDoesNotStampWhenTenantHasNoMailerDsn(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('acme', null));

        $inner = $this->createMock(MailerInterface::class);
        $captured = null;
        $inner->method('send')->willReturnCallback(function (RawMessage $m) use (&$captured): void {
            $captured = $m;
        });

        (new TenantMailerDecorator($inner, $context))->send((new Email())->to('r@example.com')->subject('s')->text('b'));

        self::assertInstanceOf(Email::class, $captured);
        self::assertFalse($captured->getHeaders()->has('X-Transport'));
    }

    public function testDoesNotOverwriteUserSuppliedXTransport(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('acme', 'smtp://u:p@h'));

        $inner = $this->createMock(MailerInterface::class);
        $captured = null;
        $inner->method('send')->willReturnCallback(function (RawMessage $m) use (&$captured): void {
            $captured = $m;
        });

        $email = (new Email())->to('r@example.com')->subject('s')->text('b');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_custom');
        (new TenantMailerDecorator($inner, $context))->send($email);

        self::assertSame('tenant_custom', $captured->getHeaders()->get('X-Transport')->getBodyAsString());
    }

    public function testRawMessageNonMessageReceivesNoStamping(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('acme', 'smtp://u:p@h', 'from@example.com'));

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects(self::once())->method('send'); // delegated unchanged

        (new TenantMailerDecorator($inner, $context))->send(new RawMessage('raw payload'));
        // No exception, no headers manipulation attempted on RawMessage (which has no getHeaders()).
    }

    public function testInnerThrowsPropagatesUnchanged(): void
    {
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('acme', 'smtp://u:p@h'));

        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(new TransportException('boom'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('boom');

        (new TenantMailerDecorator($inner, $context))->send((new Email())->to('r@example.com')->subject('s')->text('b'));
    }
}
