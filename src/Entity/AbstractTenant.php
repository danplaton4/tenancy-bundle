<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\TenantInterface;

/**
 * MappedSuperclass base for tenant entities.
 *
 * Custom tenant entities (e.g. when adding columns like `brandColor`, `plan`,
 * `billingId`) MUST extend this class rather than {@see Tenant}. Doctrine
 * forbids one #[ORM\Entity] from extending another without an explicit
 * inheritance strategy (SINGLE_TABLE + discriminator, or JOINED); using a
 * MappedSuperclass sidesteps that requirement and keeps the demo schema as a
 * single `tenancy_tenants` table owned entirely by the subclass.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractTenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 63)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 253, nullable: true)]
    private ?string $domain = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $connectionConfig = [];

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    // Mailer config (Phase 20 / BOOT-04).
    // Users with a custom Tenant entity can equivalently `use \Tenancy\Bundle\Mailer\TenantMailerConfigTrait;`
    // instead of inlining these 3 columns. See UPGRADE.md §0.2→0.3.
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerDsn = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerFrom = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerReplyTo = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /** @return array<string, mixed> */
    public function getConnectionConfig(): array
    {
        return $this->connectionConfig;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function getMailerDsn(): ?string
    {
        return $this->mailerDsn;
    }

    public function setMailerDsn(?string $mailerDsn): self
    {
        $this->mailerDsn = $mailerDsn;

        return $this;
    }

    public function getMailerFrom(): ?string
    {
        return $this->mailerFrom;
    }

    public function setMailerFrom(?string $mailerFrom): self
    {
        $this->mailerFrom = $mailerFrom;

        return $this;
    }

    public function getMailerReplyTo(): ?string
    {
        return $this->mailerReplyTo;
    }

    public function setMailerReplyTo(?string $mailerReplyTo): self
    {
        $this->mailerReplyTo = $mailerReplyTo;

        return $this;
    }

    /** @param array<string, mixed> $connectionConfig */
    public function setConnectionConfig(array $connectionConfig): self
    {
        $this->connectionConfig = $connectionConfig;

        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
