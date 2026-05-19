<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Thrown by SanitizingMailerDecorator instead of the underlying TransportException
 * when the DSN password component must be redacted from the message.
 *
 * Extends Symfony\Component\Mailer\Exception\TransportException so user code that
 * catches TransportException (the documented Symfony pattern) still catches this
 * exception — the type contract is preserved.
 */
final class TenantSanitizedTransportException extends TransportException
{
    // Inherits parent constructor:
    // __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
}
