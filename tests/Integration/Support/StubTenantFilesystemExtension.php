<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * Mixin trait that adds the Phase 24 filesystemConfig property + accessors to
 * any test TenantInterface stub without breaking the existing Phase 6 / Phase 2
 * / Phase 20 stubs.
 *
 * Wave 1 of Phase 24 introduces an OPTIONAL TenantFilesystemConfigTrait shipped
 * by the bundle (CONTEXT §DEC-FILE-CONFIG — NO new abstract on TenantInterface).
 * Stubs that opt-in via `use StubTenantFilesystemExtension` will be able to
 * exercise per-tenant filesystem scenarios; non-Filesystem tests stay untouched
 * (the default returns null — prefix-mode default per DEC-FILE-CONFIG).
 *
 * The Doctrine Column attribute mirrors StubTenantMailerExtension — Doctrine
 * is a require-dev dep of the bundle so the attribute is unconditional.
 */
trait StubTenantFilesystemExtension
{
    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filesystemConfig = null;

    /**
     * @param array<string, mixed>|null $config
     */
    public function setFilesystemConfig(?array $config): static
    {
        $this->filesystemConfig = $config;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFilesystemConfig(): ?array
    {
        return $this->filesystemConfig;
    }
}
