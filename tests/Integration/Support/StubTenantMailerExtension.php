<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

/**
 * Mixin trait that adds the Phase 20 Mailer accessors (mailerDsn / mailerFrom /
 * mailerReplyTo) to any test TenantInterface stub without breaking the
 * existing Phase 6 / Phase 2 stubs.
 *
 * Wave 1 of Phase 20 extends TenantInterface to require getMailerDsn() /
 * getMailerFrom() / getMailerReplyTo(). Stubs that opt-in via `use` this
 * trait will satisfy the new contract while still working for non-Mailer
 * tests (the default returns are all null — landlord fallback).
 */
trait StubTenantMailerExtension
{
    private ?string $mailerDsn = null;
    private ?string $mailerFrom = null;
    private ?string $mailerReplyTo = null;

    public function setMailerDsn(?string $dsn): static
    {
        $this->mailerDsn = $dsn;

        return $this;
    }

    public function setMailerFrom(?string $from): static
    {
        $this->mailerFrom = $from;

        return $this;
    }

    public function setMailerReplyTo(?string $replyTo): static
    {
        $this->mailerReplyTo = $replyTo;

        return $this;
    }

    public function getMailerDsn(): ?string
    {
        return $this->mailerDsn;
    }

    public function getMailerFrom(): ?string
    {
        return $this->mailerFrom;
    }

    public function getMailerReplyTo(): ?string
    {
        return $this->mailerReplyTo;
    }
}
