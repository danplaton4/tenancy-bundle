<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Stub TenantProvider for Phase 24 Filesystem integration tests.
 *
 * Pre-seeds named tenants for the 5-scenario integration suite:
 *   - 'acme'   → filesystemConfig = ['adapter_dsn' => 'memory://']
 *   - 'globex' → filesystemConfig = ['adapter_dsn' => 'memory://']
 *   - 'broken' → filesystemConfig = null  (triggers MissingFilesystemConfigException)
 *   - 'tenant_000' … 'tenant_099' → filesystemConfig = ['adapter_dsn' => 'memory://']
 *     (used by LongRunningWorkerFilesystemSimulationTest)
 *
 * Mirrors StubTenantProvider in tests/Integration/Messenger/Support/ but does
 * not require addTenant() — the fixed set is all that's needed here.
 *
 * @see ReplaceFilesystemProviderPass
 */
final class StubFilesystemTenantProvider implements TenantProviderInterface
{
    /** @var array<string, TenantInterface> */
    private array $tenants = [];

    public function __construct()
    {
        // Named tenants for integration-test scenarios
        $acme = new StubTenantWithFilesystem('acme');
        $acme->setFilesystemConfig(['adapter_dsn' => 'memory://']);
        $this->tenants['acme'] = $acme;

        $globex = new StubTenantWithFilesystem('globex');
        $globex->setFilesystemConfig(['adapter_dsn' => 'memory://']);
        $this->tenants['globex'] = $globex;

        // 'broken' has null filesystemConfig to trigger MissingFilesystemConfigException
        $broken = new StubTenantWithFilesystem('broken');
        $this->tenants['broken'] = $broken;

        // 100 numbered tenants for LongRunningWorkerFilesystemSimulationTest
        for ($i = 0; $i < 100; ++$i) {
            $slug = sprintf('tenant_%03d', $i);
            $tenant = new StubTenantWithFilesystem($slug);
            $tenant->setFilesystemConfig(['adapter_dsn' => 'memory://']);
            $this->tenants[$slug] = $tenant;
        }
    }

    public function findBySlug(string $slug): TenantInterface
    {
        if (!isset($this->tenants[$slug])) {
            throw new TenantNotFoundException($slug);
        }

        return $this->tenants[$slug];
    }

    /**
     * @return TenantInterface[]
     */
    public function findAll(): array
    {
        return array_values($this->tenants);
    }
}
