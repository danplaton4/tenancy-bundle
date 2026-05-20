<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Decorates the bundle's MailerInterface (Symfony's Mailer) to stamp the
 * active tenant's X-Transport, From, and Reply-To headers on every
 * outgoing message BEFORE the inner Mailer::send routes it.
 *
 * Stamping at THIS position is the canonical location per BOOT-04
 * acceptance bullet (b) — "stamps X-Transport BEFORE Messenger
 * serialization". The call chain at runtime is:
 *
 *   user code
 *     -> SanitizingMailerDecorator   (decoration_priority 0,  OUTERMOST)
 *     -> TenantMailerDecorator       (decoration_priority 10, INNER)
 *     -> Symfony\Component\Mailer\Mailer
 *        -> mailer.transports (TenantAwareTransportsDecorator)
 *        -> SmtpTransport / Messenger envelope
 *
 * Defense-in-depth note: TenantMessageDecorator (the MessageEvent
 * listener at priority 100) REMAINS WIRED. It catches code paths that
 * bypass MailerInterface::send and call a Transport directly. Both
 * stampers are idempotent — a user-supplied X-Transport header is
 * NEVER overwritten. Future maintainers: do NOT "simplify" by removing
 * the listener — its firing point covers a different surface area.
 *
 * Idempotency contract (mirrored from TenantMessageDecorator):
 *   - X-Transport: stamped only if absent.
 *   - From / Reply-To: stamped only on Email subclasses, only if absent,
 *     and only if the tenant has the corresponding non-null value.
 *
 * Non-Email RawMessage instances receive only X-Transport (no sender
 * identity headers — RawMessage has no headers API of its own; only
 * Message has getHeaders()). Pure RawMessage (no Message parent) is
 * passed through untouched.
 *
 * @see TenantMessageDecorator The MessageEvent listener kept as
 *      defense-in-depth.
 */
final class TenantMailerDecorator implements MailerInterface
{
    public function __construct(
        private readonly MailerInterface $inner,
        private readonly TenantContext $context,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->stamp($message);

        $this->inner->send($message, $envelope);
    }

    private function stamp(RawMessage $message): void
    {
        $tenant = $this->context->getTenant();
        if (null === $tenant) {
            return;
        }

        $dsn = $tenant->getMailerDsn();
        if (null === $dsn) {
            return;
        }

        if (!$message instanceof Message) {
            return; // pure RawMessage — no headers API; nothing to stamp.
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
}
