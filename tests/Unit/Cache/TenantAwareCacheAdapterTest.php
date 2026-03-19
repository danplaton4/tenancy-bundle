<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Cache;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

class TenantAwareCacheAdapterTest extends TestCase
{
    /** @var (AdapterInterface&NamespacedPoolInterface)&MockObject */
    private AdapterInterface&NamespacedPoolInterface $inner;

    private TenantContext $tenantContext;

    /** @var TenantInterface&MockObject */
    private TenantInterface $tenant;

    protected function setUp(): void
    {
        /** @var (AdapterInterface&NamespacedPoolInterface)&MockObject $inner */
        $inner = $this->createMockForIntersectionOfInterfaces([AdapterInterface::class, NamespacedPoolInterface::class]);
        $this->inner = $inner;

        $this->tenantContext = new TenantContext();
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tenant->method('getSlug')->willReturn('acme');
    }

    public function testGetItemWithTenantDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        /** @var (AdapterInterface&NamespacedPoolInterface)&MockObject $scopedPool */
        $scopedPool = $this->createMockForIntersectionOfInterfaces([AdapterInterface::class, NamespacedPoolInterface::class]);

        $expectedItem = new CacheItem();

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme')
            ->willReturn($scopedPool);

        $scopedPool
            ->expects($this->once())
            ->method('getItem')
            ->with('foo')
            ->willReturn($expectedItem);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $result = $adapter->getItem('foo');

        $this->assertSame($expectedItem, $result);
    }

    public function testGetItemWithNoTenantDelegatesToInnerDirectly(): void
    {
        // No tenant set on context

        $expectedItem = new CacheItem();

        $this->inner
            ->expects($this->never())
            ->method('withSubNamespace');

        $this->inner
            ->expects($this->once())
            ->method('getItem')
            ->with('foo')
            ->willReturn($expectedItem);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $result = $adapter->getItem('foo');

        $this->assertSame($expectedItem, $result);
    }

    public function testClearWithTenantClearsScopedNamespaceOnly(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        /** @var (AdapterInterface&NamespacedPoolInterface)&MockObject $scopedPool */
        $scopedPool = $this->createMockForIntersectionOfInterfaces([AdapterInterface::class, NamespacedPoolInterface::class]);

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme')
            ->willReturn($scopedPool);

        $scopedPool
            ->expects($this->once())
            ->method('clear')
            ->with('')
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $result = $adapter->clear();

        $this->assertTrue($result);
    }

    public function testSaveWithTenantDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        /** @var (AdapterInterface&NamespacedPoolInterface)&MockObject $scopedPool */
        $scopedPool = $this->createMockForIntersectionOfInterfaces([AdapterInterface::class, NamespacedPoolInterface::class]);

        $item = new CacheItem();

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme')
            ->willReturn($scopedPool);

        $scopedPool
            ->expects($this->once())
            ->method('save')
            ->with($item)
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $result = $adapter->save($item);

        $this->assertTrue($result);
    }

    public function testWithSubNamespaceReturnsCloneWithScopedInner(): void
    {
        // No tenant — withSubNamespace on adapter itself scopes the inner pool
        /** @var (AdapterInterface&NamespacedPoolInterface)&MockObject $scopedInner */
        $scopedInner = $this->createMockForIntersectionOfInterfaces([AdapterInterface::class, NamespacedPoolInterface::class]);

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('extra')
            ->willReturn($scopedInner);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $cloned = $adapter->withSubNamespace('extra');

        $this->assertNotSame($adapter, $cloned);
        $this->assertInstanceOf(TenantAwareCacheAdapter::class, $cloned);

        // No tenant set, so pool() returns inner directly — but inner is now scopedInner
        $expectedItem = new CacheItem();
        $scopedInner
            ->expects($this->once())
            ->method('getItem')
            ->with('bar')
            ->willReturn($expectedItem);

        $result = $cloned->getItem('bar');
        $this->assertSame($expectedItem, $result);
    }

    public function testImplementsAdapterInterface(): void
    {
        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertInstanceOf(AdapterInterface::class, $adapter);
    }

    public function testImplementsNamespacedPoolInterface(): void
    {
        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertInstanceOf(NamespacedPoolInterface::class, $adapter);
    }
}
