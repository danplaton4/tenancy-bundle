<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer\Fixture;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Test double for a TransportInterface that exposes a `stop()` method —
 * mirrors Symfony\Component\Mailer\Transport\Smtp\SmtpTransport::stop().
 *
 * Records every `stop()` invocation so LruTransportCache eviction / clear
 * behavior can be asserted.
 */
final class StoppableSpyTransport implements TransportInterface
{
    public int $stopCalls = 0;

    public function __construct(private readonly string $label = 'stoppable')
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        // Spy never produces a SentMessage — null is permitted by the
        // TransportInterface contract (return type is ?SentMessage).
        return null;
    }

    public function stop(): void
    {
        ++$this->stopCalls;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
