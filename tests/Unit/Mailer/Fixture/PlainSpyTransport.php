<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer\Fixture;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Test double for a TransportInterface that does NOT expose a `stop()` method —
 * mirrors transports like NullTransport / SendmailTransport for the purpose of
 * verifying LruTransportCache's `method_exists()` guard handles them gracefully.
 */
final class PlainSpyTransport implements TransportInterface
{
    public function __construct(private readonly string $label = 'plain')
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        // Spy never produces a SentMessage — null is permitted by the
        // TransportInterface contract (return type is ?SentMessage).
        return null;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
