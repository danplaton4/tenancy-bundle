<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Stamps the tenant's X-Transport, From, and Reply-To headers on outgoing
 * Mailer messages.
 *
 * Listens on MessageEvent at priority 100 — runs before Symfony's own
 * default-priority listeners (e.g. MessengerTransportListener at 0).
 *
 * The load-bearing firing point is MessageEvent(isQueued=false) — the
 * transport-level event firing in both sync HTTP context and worker context
 * after TenantWorkerMiddleware has restored the tenant. This is the correct
 * event firing point per RESEARCH Finding 2: pre-dispatch (isQueued=true)
 * MessageEvents fire on a CLONE that is discarded; the original $message
 * goes into SendEmailMessage and is what the worker sees on the receiving
 * side.
 *
 * Idempotency: existing X-Transport / From / Reply-To headers on the message
 * are NEVER overwritten — user-supplied values win.
 */
final class TenantMessageDecorator implements EventSubscriberInterface
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function onMessage(MessageEvent $event): void
    {
        $tenant = $this->context->getTenant();
        if (null === $tenant) {
            return;
        }

        $dsn = $tenant->getMailerDsn();
        if (null === $dsn) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Message) {
            return;
        }

        $headers = $message->getHeaders();

        if (!$headers->has('X-Transport')) {
            $headers->addTextHeader('X-Transport', 'tenant_'.$tenant->getSlug());
        }

        if (!$message instanceof Email) {
            return;
        }

        $from = $tenant->getMailerFrom();
        if (null !== $from && !$headers->has('From')) {
            $message->from($from);
        }

        $replyTo = $tenant->getMailerReplyTo();
        if (null !== $replyTo && !$headers->has('Reply-To')) {
            $message->replyTo($replyTo);
        }
    }

    /**
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => ['onMessage', 100]];
    }
}
