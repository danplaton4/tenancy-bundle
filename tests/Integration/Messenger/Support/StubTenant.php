<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger\Support;

use Tenancy\Bundle\TenantInterface;

/**
 * Simple stub tenant for Messenger integration tests.
 */
final class StubTenant implements TenantInterface
{
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
