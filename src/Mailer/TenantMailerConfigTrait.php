<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

use Doctrine\ORM\Mapping as ORM;

/**
 * Default implementation of the mailer-config methods on TenantInterface.
 *
 * Users with a custom Tenant entity can `use TenantMailerConfigTrait;` to
 * inherit the three nullable columns and their getters/setters. Equivalent to
 * implementing getMailerDsn/getMailerFrom/getMailerReplyTo by hand.
 *
 * The #[ORM\Column] attributes are only honored if doctrine/orm is installed;
 * with Doctrine absent the trait still works as plain PHP property storage.
 */
trait TenantMailerConfigTrait
{
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerDsn = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerFrom = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerReplyTo = null;

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

    public function setMailerDsn(?string $mailerDsn): static
    {
        $this->mailerDsn = $mailerDsn;

        return $this;
    }

    public function setMailerFrom(?string $mailerFrom): static
    {
        $this->mailerFrom = $mailerFrom;

        return $this;
    }

    public function setMailerReplyTo(?string $mailerReplyTo): static
    {
        $this->mailerReplyTo = $mailerReplyTo;

        return $this;
    }
}
