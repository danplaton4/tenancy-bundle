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

    /**
     * withSubNamespace() declares `static` return type, so PHPUnit mock enforces
     * the return value is of the same mock class as $this->inner.
     * We configure $inner->withSubNamespace('acme.') to return $this->inner itself
     * (acting as both the original pool and the scoped pool).
     * Then we assert the final operation (getItem, clear, save) was called on $inner.
     */
    public function testGetItemWithTenantDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $expectedItem = new CacheItem();

        // inner acts as both the original and the scoped pool (static return type constraint)
        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
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
        // No tenant set on context — inner->withSubNamespace must never be called

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

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
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

        $item = new CacheItem();

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
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
        // Calling withSubNamespace on the adapter returns a new adapter instance.
        // The cloned adapter's inner is set to inner->withSubNamespace('extra').
        // Since no tenant is active, getItem on the clone delegates to the scoped inner.

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('extra')
            ->willReturnSelf(); // static return type: returns $inner itself

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $cloned = $adapter->withSubNamespace('extra');

        $this->assertNotSame($adapter, $cloned);
        $this->assertInstanceOf(TenantAwareCacheAdapter::class, $cloned);

        // No tenant set, pool() returns cloned inner (which is still $this->inner)
        $expectedItem = new CacheItem();
        $this->inner
            ->expects($this->once())
            ->method('getItem')
            ->with('bar')
            ->willReturn($expectedItem);

        $result = $cloned->getItem('bar');
        $this->assertSame($expectedItem, $result);
    }

    public function testGetItemsDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $expectedItems = [new CacheItem()];

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('getItems')
            ->with(['foo', 'bar'])
            ->willReturn($expectedItems);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $result = $adapter->getItems(['foo', 'bar']);

        $this->assertSame($expectedItems, $result);
    }

    public function testHasItemDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('hasItem')
            ->with('foo')
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertTrue($adapter->hasItem('foo'));
    }

    public function testDeleteItemDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('deleteItem')
            ->with('foo')
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertTrue($adapter->deleteItem('foo'));
    }

    public function testDeleteItemsDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('deleteItems')
            ->with(['foo', 'bar'])
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertTrue($adapter->deleteItems(['foo', 'bar']));
    }

    public function testSaveDeferredDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $item = new CacheItem();

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('saveDeferred')
            ->with($item)
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertTrue($adapter->saveDeferred($item));
    }

    public function testCommitDelegatesToScopedPool(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme.')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
        $this->assertTrue($adapter->commit());
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

    public function testCustomCachePrefixSeparatorIsUsed(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $expectedItem = new CacheItem();

        $this->inner
            ->expects($this->once())
            ->method('withSubNamespace')
            ->with('acme_')
            ->willReturnSelf();

        $this->inner
            ->expects($this->once())
            ->method('getItem')
            ->with('foo')
            ->willReturn($expectedItem);

        $adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext, '_');
        $result = $adapter->getItem('foo');

        $this->assertSame($expectedItem, $result);
    }
}
