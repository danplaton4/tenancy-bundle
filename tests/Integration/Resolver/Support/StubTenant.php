<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\StubTenantMailerExtension;

/**
 * Simple stub tenant for Origin resolver integration tests.
 */
final class StubTenant implements TenantInterface
{
    use StubTenantMailerExtension;

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
}
