<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filesystem;

use Doctrine\ORM\Mapping as ORM;

/**
 * Default implementation of the (optional) filesystem-config accessor on a tenant entity.
 *
 * Users with a custom Tenant entity can `use TenantFilesystemConfigTrait;` to
 * inherit the nullable JSON column and its getter/setter pair — equivalent to
 * implementing getFilesystemConfig()/setFilesystemConfig() by hand. The shipped
 * {@see \Tenancy\Bundle\Entity\AbstractTenant} inlines the same column for the
 * out-of-the-box experience.
 *
 * Per .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-CONFIG
 * the TenantInterface deliberately does NOT carry an abstract
 * `getFilesystemConfig(): ?array` method — adoption is OPTIONAL via this trait,
 * preserving a zero BC break for v0.3 downstreams with custom Tenant entities.
 *
 * Return shape (validated downstream in Plan 24-06 / Plan 24-07, NOT here):
 *
 * ```php
 * ?array{
 *   prefix?: string,           // prefix mode override — defaults to "tenant_{slug}/"
 *   adapter_dsn?: string,      // per_tenant_adapter mode — e.g. "s3:///bucket?region=eu-central-1"
 *   services?: array<string>,  // optional: limit scoping to these service IDs (empty = all tagged)
 * }
 * ```
 *
 * The #[ORM\Column] attribute is only honored when doctrine/orm is installed;
 * with Doctrine absent the trait still works as plain PHP property storage.
 *
 * See UPGRADE.md §0.3→0.4 (owned by Plan 24-09) for the migration path.
 */
trait TenantFilesystemConfigTrait
{
    /**
     * @var array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filesystemConfig = null;

    /**
     * @return array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null
     */
    public function getFilesystemConfig(): ?array
    {
        return $this->filesystemConfig;
    }

    /**
     * @param array{prefix?: string, adapter_dsn?: string, services?: array<string>}|null $filesystemConfig
     */
    public function setFilesystemConfig(?array $filesystemConfig): static
    {
        $this->filesystemConfig = $filesystemConfig;

        return $this;
    }
}
