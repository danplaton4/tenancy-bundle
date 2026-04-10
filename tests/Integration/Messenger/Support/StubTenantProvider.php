<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Messenger\Support;

use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Stub TenantProvider for Messenger integration tests.
 * Holds a map of slug => TenantInterface for deterministic lookups.
 */
final class StubTenantProvider implements TenantProviderInterface
{
    /** @var array<string, TenantInterface> */
    private array $tenants = [];

    public function addTenant(TenantInterface $tenant): void
    {
        $this->tenants[$tenant->getSlug()] = $tenant;
    }

    public function findBySlug(string $slug): TenantInterface
    {
        if (!isset($this->tenants[$slug])) {
            throw new TenantNotFoundException($slug);
        }

        return $this->tenants[$slug];
    }

    public function findAll(): array
    {
        return array_values($this->tenants);
    }
}
