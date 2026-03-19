<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\BootstrapperTestKernel;

/**
 * Integration tests proving TenantAwareCacheAdapter namespace isolation.
 *
 * Verifies:
 *   - cache.app is decorated with TenantAwareCacheAdapter in the real DI container
 *   - write-as-tenant-A / read-as-tenant-B is a cache miss (different namespace)
 *   - clearing tenant A cache namespace does not invalidate tenant B entries
 *   - no-tenant context delegates to the global pool (no subnamespace)
 */
final class CacheBootstrapperIntegrationTest extends TestCase
{
    private static BootstrapperTestKernel $kernel;
    private static string $dbPath;

    private TenantAwareCacheAdapter $cache;
    private TenantContext $tenantContext;

    public static function setUpBeforeClass(): void
    {
        static::$dbPath = sys_get_temp_dir() . '/tenancy_bootstrapper_cache_test.db';
        // Note: BootstrapperTestKernel(env='cache_test') stores DB at tenancy_bootstrapper_cache_test.db

        if (file_exists(static::$dbPath)) {
            unlink(static::$dbPath);
        }

        static::$kernel = new BootstrapperTestKernel('cache_test', false);
        static::$kernel->boot();

        $container = static::$kernel->getContainer();

        /** @var EntityManagerInterface $em */
        $em         = $container->get('doctrine.orm.default_entity_manager');
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();

        if (file_exists(static::$dbPath)) {
            unlink(static::$dbPath);
        }
    }

    protected function setUp(): void
    {
        $container = static::$kernel->getContainer();

        /** @var TenantAwareCacheAdapter $cache */
        $cache = $container->get('cache.app');
        $this->cache = $cache;

        /** @var TenantContext $tenantContext */
        $tenantContext = $container->get('tenancy.context');
        $this->tenantContext = $tenantContext;

        // Start each test with no tenant and a cleared cache
        $this->tenantContext->clear();
        $this->cache->clear();
    }

    private function makeTenant(string $slug): TenantInterface
    {
        return new class ($slug) implements TenantInterface {
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
        };
    }

    public function testCacheAppIsDecoratedByTenantAwareAdapter(): void
    {
        $container = static::$kernel->getContainer();
        $cache     = $container->get('cache.app');

        $this->assertInstanceOf(TenantAwareCacheAdapter::class, $cache);
    }

    public function testCacheIsolationBetweenTenants(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');

        // Write under tenant A
        $this->tenantContext->setTenant($tenantA);
        $item = $this->cache->getItem('greeting');
        $item->set('hello-alpha');
        $this->cache->save($item);

        // Verify tenant A can read it back
        $this->assertTrue($this->cache->getItem('greeting')->isHit(), 'Tenant A must hit its own cache entry');
        $this->assertSame('hello-alpha', $this->cache->getItem('greeting')->get(), 'Tenant A value must match');

        // Switch to tenant B — must be a cache miss
        $this->tenantContext->clear();
        $this->tenantContext->setTenant($tenantB);
        $this->assertFalse(
            $this->cache->getItem('greeting')->isHit(),
            'Tenant B must NOT see tenant A cache entry (different namespace)',
        );

        // Write under tenant B
        $item = $this->cache->getItem('greeting');
        $item->set('hello-beta');
        $this->cache->save($item);

        // Switch back to tenant A — must still see hello-alpha, not hello-beta
        $this->tenantContext->clear();
        $this->tenantContext->setTenant($tenantA);
        $this->assertTrue($this->cache->getItem('greeting')->isHit(), 'Tenant A must still hit its cache entry');
        $this->assertSame(
            'hello-alpha',
            $this->cache->getItem('greeting')->get(),
            'Tenant A value must still be hello-alpha — not overwritten by tenant B',
        );
    }

    public function testClearTenantACacheDoesNotAffectTenantB(): void
    {
        $tenantA = $this->makeTenant('alpha');
        $tenantB = $this->makeTenant('beta');

        // Write under tenant A
        $this->tenantContext->setTenant($tenantA);
        $itemA = $this->cache->getItem('key-a');
        $itemA->set('value-a');
        $this->cache->save($itemA);

        // Write under tenant B
        $this->tenantContext->clear();
        $this->tenantContext->setTenant($tenantB);
        $itemB = $this->cache->getItem('key-b');
        $itemB->set('value-b');
        $this->cache->save($itemB);

        // Clear tenant A's namespace
        $this->tenantContext->clear();
        $this->tenantContext->setTenant($tenantA);
        $this->cache->clear();

        // Tenant A's entry must be gone
        $this->assertFalse(
            $this->cache->getItem('key-a')->isHit(),
            'Tenant A cache must be cleared after pool->clear()',
        );

        // Tenant B's entry must still be present
        $this->tenantContext->clear();
        $this->tenantContext->setTenant($tenantB);
        $this->assertTrue(
            $this->cache->getItem('key-b')->isHit(),
            'Tenant B cache must NOT be affected by clearing tenant A namespace',
        );
        $this->assertSame('value-b', $this->cache->getItem('key-b')->get());
    }

    public function testNoTenantDelegatesToGlobalPool(): void
    {
        $tenantA = $this->makeTenant('alpha');

        // No tenant set — writes go to global pool
        $item = $this->cache->getItem('global-key');
        $item->set('global-value');
        $this->cache->save($item);

        // Global key must be readable without tenant
        $this->assertTrue($this->cache->getItem('global-key')->isHit(), 'Global cache entry must be readable without tenant');
        $this->assertSame('global-value', $this->cache->getItem('global-key')->get());

        // Switch to tenant A — global-key must NOT be visible (different namespace)
        $this->tenantContext->setTenant($tenantA);
        $this->assertFalse(
            $this->cache->getItem('global-key')->isHit(),
            'Global cache key must NOT be visible under tenant A namespace',
        );
    }
}
