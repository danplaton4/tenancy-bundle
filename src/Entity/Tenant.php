<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
#[ORM\HasLifecycleCallbacks]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 63)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 253, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(type: 'json')]
    private array $connectionConfig = [];

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
