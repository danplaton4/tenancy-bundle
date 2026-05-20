<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerExceptionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Tenancy\Bundle\Exception\TenantSanitizedTransportException;

/**
 * Wraps the bundle's MailerInterface to redact DSN credentials from any
 * mailer-component exception propagating out of the inner mailer.
 *
 * Catches BOTH TransportExceptionInterface (the documented Symfony pattern)
 * AND the broader Mailer ExceptionInterface (bridge-factory throws —
 * UnsupportedSchemeException, Mailer\Exception\InvalidArgumentException,
 * Mailer\Exception\LogicException). The two arms have distinct re-throw
 * types:
 *   - TransportExceptionInterface -> TenantSanitizedTransportException
 *     (preserves IS-A TransportException for user catch-blocks)
 *   - other Mailer\Exception\ExceptionInterface kinds -> \RuntimeException
 *     (these were never TransportException subtypes, so wrapping them
 *     in TenantSanitizedTransportException would change the visible type)
 *
 * In both cases the message is run through DsnSanitizer::redact.
 *
 * @see DsnSanitizer for the canonical redaction regex
 * @see .planning/phases/20-mailer-bootstrapper/20-REVIEW.md WR-01
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
            // TransportException kinds — preserve the IS-A TransportException
            // type contract for user catch-blocks.
            $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
            throw new TenantSanitizedTransportException($sanitized, $e->getCode(), $e);
        } catch (\Throwable $e) {
            // Plan 20-10 / REVIEW WR-01 — bridge-factory throws
            // (UnsupportedSchemeException, Mailer\Exception\InvalidArgumentException,
            // Mailer\Exception\LogicException, ...) implement Mailer's
            // ExceptionInterface but NOT TransportExceptionInterface, so they
            // are NOT covered by MailerInterface::send's `@throws` declaration
            // (which is why we must catch \Throwable here rather than the
            // narrower MailerExceptionInterface — PHPStan would flag the
            // narrower catch as dead code per the interface contract, even
            // though real bridge factories DO throw these from send() in
            // practice). We then narrow at runtime: only MailerExceptionInterface
            // gets sanitized + wrapped; anything else re-throws as-is to
            // preserve the existing testNonTransportExceptionPropagatesAsIs
            // contract for non-mailer exceptions.
            if (!$e instanceof MailerExceptionInterface) {
                throw $e;
            }
            // Re-throw as \RuntimeException with redacted message so user
            // code that catches TransportException (the documented Symfony
            // pattern) is unaffected (this branch's exceptions were never
            // TransportException kinds to begin with), and ANY mailer-component
            // exception with a DSN in its message gets sanitized.
            $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
            throw new \RuntimeException($sanitized, $e->getCode(), $e);
        }
    }
}
