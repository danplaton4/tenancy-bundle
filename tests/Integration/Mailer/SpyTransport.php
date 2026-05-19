<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Test-only TransportInterface that records every send() call along with
 * the DSN string the transport was instantiated with.
 *
 * Enables the async canary (BOOT-04-g) to assert WHICH DSN the worker used
 * after restoring tenant context — without contacting a real SMTP server.
 *
 * Security note: stores DSN strings verbatim in test memory only. Tests MUST
 * use synthetic DSNs (e.g. `smtp://test:test@spy:0`) — never real credentials.
 */
final class SpyTransport implements TransportInterface
{
    /** @var list<array{message: RawMessage, envelope: ?Envelope, dsn: string}> */
    private array $sends = [];

    public function __construct(private readonly string $dsn)
    {
    }

    /** @phpstan-ignore-next-line return.unusedType — null permitted by TransportInterface contract */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->sends[] = ['message' => $message, 'envelope' => $envelope, 'dsn' => $this->dsn];

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    /** @return list<array{message: RawMessage, envelope: ?Envelope, dsn: string}> */
    public function getSends(): array
    {
        return $this->sends;
    }

    public function __toString(): string
    {
        return 'spy:'.$this->dsn;
    }
}
