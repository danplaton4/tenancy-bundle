<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\DoctrineTenantProvider;
use Tenancy\Bundle\TenantInterface;

final class DoctrineTenantProviderTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private EntityRepository $repository;
    private CacheInterface $cache;
    private DoctrineTenantProvider $provider;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->entityManager
            ->method('getRepository')
            ->with('App\Entity\Tenant')
            ->willReturn($this->repository);

        $this->provider = new DoctrineTenantProvider(
            $this->entityManager,
            $this->cache,
            'App\Entity\Tenant'
        );
    }

    public function testFindBySlugReturnsTenantFromCache(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('isActive')->willReturn(true);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('tenancy.tenant.acme', $this->isCallable())
            ->willReturn($tenant);

        $result = $this->provider->findBySlug('acme');

        $this->assertSame($tenant, $result);
    }

    public function testFindBySlugThrowsTenantNotFoundWhenCacheReturnsNull(): void
    {
        $this->cache
            ->method('get')
            ->willReturn(null);

        $this->expectException(TenantNotFoundException::class);
        $this->provider->findBySlug('nonexistent');
    }

    public function testFindBySlugThrowsTenantInactiveExceptionForInactiveTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('isActive')->willReturn(false);

        $this->cache
            ->method('get')
            ->willReturn($tenant);

        $this->expectException(TenantInactiveException::class);
        $this->provider->findBySlug('inactive-slug');
    }

    public function testFindBySlugCacheCallbackFetchesFromRepository(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('isActive')->willReturn(true);

        $this->repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['slug' => 'acme'])
            ->willReturn($tenant);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with(300);

        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->provider->findBySlug('acme');

        $this->assertSame($tenant, $result);
    }

    public function testFindBySlugCacheKeyIncludesSlug(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('isActive')->willReturn(true);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('tenancy.tenant.my-slug', $this->isCallable())
            ->willReturn($tenant);

        $this->provider->findBySlug('my-slug');
    }

    public function testIsActiveCheckRunsAfterCacheRetrieval(): void
    {
        // Inactive tenants ARE cached; is_active check runs AFTER cache retrieval
        // This tests Pitfall 3 from the research: cache inactive tenants to prevent DB hammering
        $inactiveTenant = $this->createMock(TenantInterface::class);
        $inactiveTenant->method('isActive')->willReturn(false);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->method('expiresAfter');

        $this->repository
            ->expects($this->once()) // DB hit happens once (cache callback)
            ->method('findOneBy')
            ->willReturn($inactiveTenant);

        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        // is_active check should happen AFTER returning from cache
        try {
            $this->provider->findBySlug('inactive');
            $this->fail('Expected TenantInactiveException');
        } catch (TenantInactiveException $e) {
            $this->assertInstanceOf(TenantInactiveException::class, $e);
        }
    }
}
