<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

use Symfony\Component\Mailer\Exception\TransportException;
use Tenancy\Bundle\Mailer\DsnSanitizer;

/**
 * Thrown by SanitizingMailerDecorator instead of the underlying
 * TransportException when the DSN password component must be redacted
 * from the message.
 *
 * Extends Symfony\Component\Mailer\Exception\TransportException so user
 * code that catches TransportException (the documented Symfony pattern)
 * still catches this exception — the type contract is preserved.
 *
 * Security invariant (Plan 20-10 / REVIEW BL-01): the parent class
 * exposes getDebug() which Symfony bridges populate with SMTP transcripts
 * (via appendDebug) that often contain the full DSN string. This subclass
 * overrides the constructor to copy + sanitize the previous
 * TransportException's getDebug() output via DsnSanitizer::redact at
 * construction time, then mirror the sanitized payload into this
 * instance's own debug buffer via appendDebug(). The $previous chain
 * link is preserved (callers walking getPrevious() get the cause chain
 * for stack traces), but the wrapper's own getDebug() always returns a
 * redacted string. Future maintainers: do NOT remove the constructor
 * override — the parent class's getDebug() is a documented data-leak
 * vector per BL-01.
 *
 * Constructor semantics:
 *   - $previous is a TransportException with non-empty getDebug() →
 *     this->appendDebug(DsnSanitizer::redact($previous->getDebug()))
 *     (the wrapper's own $debug starts empty per parent's default, so
 *     appendDebug on a fresh wrapper is equivalent to setDebug).
 *   - $previous is a non-TransportException (or null) → debug stays ''
 *     (the parent class default).
 */
final class TenantSanitizedTransportException extends TransportException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        if ($previous instanceof TransportException) {
            $debug = $previous->getDebug();
            if ('' !== $debug) {
                $this->appendDebug(DsnSanitizer::redact($debug) ?? '');
            }
        }
    }
}
