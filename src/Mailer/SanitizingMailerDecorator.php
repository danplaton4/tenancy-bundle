<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Tenancy\Bundle\Exception\TenantSanitizedTransportException;

/**
 * Wraps the bundle's MailerInterface to redact DSN credentials from any
 * TransportException propagating out of the inner mailer.
 *
 * Catches the documented Symfony interface (TransportExceptionInterface) so
 * concrete subclasses (e.g. EnvelopeAwareTransportException) are also covered.
 * Re-throws as TenantSanitizedTransportException which still IS-A TransportException —
 * preserves user catch-blocks on the parent class.
 *
 * @see DsnSanitizer for the canonical redaction regex
 */
final class SanitizingMailerDecorator implements MailerInterface
{
    public function __construct(private readonly MailerInterface $inner)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        try {
            $this->inner->send($message, $envelope);
        } catch (TransportExceptionInterface $e) {
            $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
            throw new TenantSanitizedTransportException($sanitized, $e->getCode(), $e);
        }
    }
}
