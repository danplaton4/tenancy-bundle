<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Tenancy\Bundle\Entity\Tenant;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Stub TenantProvider for SharedEntity integration tests.
 *
 * Returns exactly two test tenants (tenant_a, tenant_b) with SQLite file
 * connection configs, so the fan-out loop has two real targets without
 * requiring a real Doctrine tenant provider.
 *
 * Use getTenantA() / getTenantB() in tests to get the same path strings
 * used by the kernel.
 */
final class StubMultiTenantProvider implements TenantProviderInterface
{
    private static function buildTenantA(): Tenant
    {
        return (new Tenant('tenant_a', 'Tenant A'))
            ->setConnectionConfig(['path' => self::getTenantAPath()]);
    }

    private static function buildTenantB(): Tenant
    {
        return (new Tenant('tenant_b', 'Tenant B'))
            ->setConnectionConfig(['path' => self::getTenantBPath()]);
    }

    public static function getTenantAPath(): string
    {
        return sys_get_temp_dir().'/tenancy_shared_test_tenant_a.db';
    }

    public static function getTenantBPath(): string
    {
        return sys_get_temp_dir().'/tenancy_shared_test_tenant_b.db';
    }

    public function findAll(): array
    {
        return [
            self::buildTenantA(),
            self::buildTenantB(),
        ];
    }

    public function findBySlug(string $slug): TenantInterface
    {
        return match ($slug) {
            'tenant_a' => self::buildTenantA(),
            'tenant_b' => self::buildTenantB(),
            default => throw new TenantNotFoundException(sprintf('StubMultiTenantProvider: tenant "%s" not found.', $slug)),
        };
    }
}
