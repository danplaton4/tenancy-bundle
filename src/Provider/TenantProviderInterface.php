<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Provider;

use Tenancy\Bundle\TenantInterface;

interface TenantProviderInterface
{
    /**
     * Find a tenant by its slug.
     *
     * @throws \Tenancy\Bundle\Exception\TenantNotFoundException when no tenant exists with the given slug
     * @throws \Tenancy\Bundle\Exception\TenantInactiveException when the tenant exists but is not active
     */
    public function findBySlug(string $slug): TenantInterface;
}
