<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\StubTenantFilesystemExtension;

/**
 * Minimal stub TenantInterface implementation for Phase 24 filesystem
 * integration tests.
 *
 * Carries the StubTenantFilesystemExtension trait so getFilesystemConfig()
 * and setFilesystemConfig() are available for per_tenant_adapter scenarios.
 * Mirrors tests/Integration/Mailer/StubTenantWithMailer.php for the
 * Filesystem equivalents.
 *
 * @see StubTenantFilesystemExtension
 */
final class StubTenantWithFilesystem implements TenantInterface
{
    use StubTenantFilesystemExtension;

    public function __construct(private readonly string $slug)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDomain(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function getConnectionConfig(): array
    {
        return [];
    }

    public function getName(): string
    {
        return $this->slug;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function isInMaintenance(): bool
    {
        return false;
    }

    public function getMailerDsn(): ?string
    {
        return null;
    }

    public function getMailerFrom(): ?string
    {
        return null;
    }

    public function getMailerReplyTo(): ?string
    {
        return null;
    }
}
