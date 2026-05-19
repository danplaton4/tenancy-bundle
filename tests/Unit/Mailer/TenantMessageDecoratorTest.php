<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Header\Headers;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Mailer\TenantMessageDecorator;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\StubTenantMailerExtension;

/**
 * Behavior tests for TenantMessageDecorator.
 *
 * Covers BOOT-04-b per .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md:
 * X-Transport header (and From / Reply-To when Email) stamped on MessageEvent at
 * priority 100, with idempotency guards and tenant-context early-return.
 *
 * Per RESEARCH Finding 2, the load-bearing firing point is MessageEvent
 * (isQueued=false) — the transport-level event firing in both sync HTTP context
 * and worker context. We do NOT filter on isQueued so the listener also runs
 * for the pre-dispatch event (Symfony stamps via getStamps anyway), but the
 * load-bearing path is isQueued=false.
 */
final class TenantMessageDecoratorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(MessageEvent::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
    }

    private function makeTenant(?string $dsn, ?string $from = null, ?string $replyTo = null, string $slug = 'acme'): TenantInterface
    {
        $tenant = new class implements TenantInterface {
            use StubTenantMailerExtension;

            private string $slug = 'acme';

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
        $tenant->setMailerFrom($from);
        $tenant->setMailerReplyTo($replyTo);

        return $tenant;
    }

    private function makeContext(?TenantInterface $tenant): TenantContext
    {
        $ctx = new TenantContext();
        if (null !== $tenant) {
            $ctx->setTenant($tenant);
        }

        return $ctx;
    }

    private function makeEvent(Message $message): MessageEvent
    {
        return new MessageEvent($message, Envelope::create($message), 'transport_id', false);
    }

    public function testSubscribesToMessageEventAtPriority100(): void
    {
        $events = TenantMessageDecorator::getSubscribedEvents();
        $this->assertArrayHasKey(MessageEvent::class, $events);
        $this->assertSame(['onMessage', 100], $events[MessageEvent::class]);
    }

    public function testNoOpWhenTenantContextEmpty(): void
    {
        $decorator = new TenantMessageDecorator($this->makeContext(null));

        $email = (new Email())->to('a@b')->text('hi');
        $decorator->onMessage($this->makeEvent($email));

        $this->assertFalse($email->getHeaders()->has('X-Transport'));
        $this->assertSame([], $email->getFrom());
    }

    public function testNoOpWhenTenantHasNullMailerDsn(): void
    {
        $tenant = $this->makeTenant(null, 'no-dsn@example.com');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $email = (new Email())->to('a@b')->text('hi');
        $decorator->onMessage($this->makeEvent($email));

        $this->assertFalse($email->getHeaders()->has('X-Transport'));
        $this->assertSame([], $email->getFrom());
    }

    public function testStampsXTransportAndFromOnEmail(): void
    {
        $tenant = $this->makeTenant('smtp://acme', 'acme@example.com', null, 'acme');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $email = (new Email())->to('a@b')->text('hi');
        $decorator->onMessage($this->makeEvent($email));

        $xt = $email->getHeaders()->get('X-Transport');
        $this->assertNotNull($xt);
        $this->assertSame('tenant_acme', $xt->getBodyAsString());

        $froms = $email->getFrom();
        $this->assertCount(1, $froms);
        $this->assertInstanceOf(Address::class, $froms[0]);
        $this->assertSame('acme@example.com', $froms[0]->getAddress());
    }

    public function testStampsReplyToWhenConfigured(): void
    {
        $tenant = $this->makeTenant('smtp://acme', 'acme@example.com', 'support@acme.com', 'acme');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $email = (new Email())->to('a@b')->text('hi');
        $decorator->onMessage($this->makeEvent($email));

        $replyTo = $email->getReplyTo();
        $this->assertCount(1, $replyTo);
        $this->assertSame('support@acme.com', $replyTo[0]->getAddress());
    }

    public function testDoesNotOverwriteExistingXTransportHeader(): void
    {
        $tenant = $this->makeTenant('smtp://acme', 'acme@example.com', null, 'acme');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $email = (new Email())->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'marketing');

        $decorator->onMessage($this->makeEvent($email));

        $this->assertSame('marketing', $email->getHeaders()->get('X-Transport')->getBodyAsString());
    }

    public function testDoesNotOverwriteExistingFromHeader(): void
    {
        $tenant = $this->makeTenant('smtp://acme', 'acme@example.com', null, 'acme');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $email = (new Email())->from('user@example.com')->to('a@b')->text('hi');
        $decorator->onMessage($this->makeEvent($email));

        $froms = $email->getFrom();
        $this->assertCount(1, $froms);
        $this->assertSame('user@example.com', $froms[0]->getAddress());
    }

    public function testNoReplyToAddedWhenTenantReplyToNull(): void
    {
        $tenant = $this->makeTenant('smtp://acme', 'acme@example.com', null, 'acme');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $email = (new Email())->to('a@b')->text('hi');
        $decorator->onMessage($this->makeEvent($email));

        $this->assertSame([], $email->getReplyTo());
        $this->assertFalse($email->getHeaders()->has('Reply-To'));
    }

    public function testStampsXTransportButNotFromOnNonEmailMessage(): void
    {
        // Non-Email Message subclass — listener still stamps X-Transport (header-level work)
        // but cannot call ->from() / ->replyTo() (Email-only setters).
        $tenant = $this->makeTenant('smtp://acme', 'acme@example.com', 'support@acme.com', 'acme');
        $decorator = new TenantMessageDecorator($this->makeContext($tenant));

        $headers = new Headers();
        $message = new Message($headers);

        $decorator->onMessage($this->makeEvent($message));

        $this->assertTrue($message->getHeaders()->has('X-Transport'));
        $this->assertSame('tenant_acme', $message->getHeaders()->get('X-Transport')->getBodyAsString());
        // No From header should be added because Email::from() is the only path that mutates From.
        $this->assertFalse($message->getHeaders()->has('From'));
    }
}
