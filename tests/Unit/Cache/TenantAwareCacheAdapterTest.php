<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Cache;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

class TenantAwareCacheAdapterTest extends TestCase
{
    /** @var (AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface)&MockObject */
    private AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $inner;

    private TenantContext $tenantContext;

    /** @var TenantInterface&MockObject */
    private TenantInterface $tenant;

    private TenantAwareCacheAdapter $adapter;

    protected function setUp(): void
    {
        /** @var (AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface)&MockObject $inner */
        $inner = $this->createMockForIntersectionOfInterfaces([
            AdapterInterface::class,
            CacheInterface::class,
            NamespacedPoolInterface::class,
            PruneableInterface::class,
            ResettableInterface::class,
        ]);
        $this->inner = $inner;

        $this->tenantContext = new TenantContext();
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tenant->method('getSlug')->willReturn('acme');

        $this->adapter = new TenantAwareCacheAdapter($this->inner, $this->tenantContext);
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

        $result = $this->adapter->getItem('foo');

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

        $result = $this->adapter->getItem('foo');

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

        $result = $this->adapter->clear();

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

        $result = $this->adapter->save($item);

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

        $cloned = $this->adapter->withSubNamespace('extra');

        $this->assertNotSame($this->adapter, $cloned);
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

        $result = $this->adapter->getItems(['foo', 'bar']);

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

        $this->assertTrue($this->adapter->hasItem('foo'));
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

        $this->assertTrue($this->adapter->deleteItem('foo'));
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

        $this->assertTrue($this->adapter->deleteItems(['foo', 'bar']));
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

        $this->assertTrue($this->adapter->saveDeferred($item));
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

        $this->assertTrue($this->adapter->commit());
    }

    public function testImplementsAdapterInterface(): void
    {
        $this->assertInstanceOf(AdapterInterface::class, $this->adapter);
    }

    public function testImplementsNamespacedPoolInterface(): void
    {
        $this->assertInstanceOf(NamespacedPoolInterface::class, $this->adapter);
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

    public function testImplementsFullCacheAppSubstitutionSurface(): void
    {
        $reflection = new \ReflectionClass(TenantAwareCacheAdapter::class);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertContains(AdapterInterface::class, $interfaces);
        $this->assertContains(CacheInterface::class, $interfaces);
        $this->assertContains(NamespacedPoolInterface::class, $interfaces);
        $this->assertContains(PruneableInterface::class, $interfaces);
        $this->assertContains(ResettableInterface::class, $interfaces);
    }

    public function testGetWithoutTenantDelegatesToInner(): void
    {
        $this->inner
            ->expects($this->once())
            ->method('get')
            ->with('foo', $this->isType('callable'))
            ->willReturn('bar');

        $result = $this->adapter->get('foo', fn () => 'bar');
        $this->assertSame('bar', $result);
    }

    public function testGetWithTenantAppliesSubNamespace(): void
    {
        $this->tenantContext->setTenant($this->tenant);

        $this->inner->expects($this->once())->method('withSubNamespace')->with('acme.')->willReturnSelf();
        $this->inner->expects($this->once())->method('get')->with('foo', $this->isType('callable'))->willReturn('bar');

        $result = $this->adapter->get('foo', fn () => 'bar');
        $this->assertSame('bar', $result);
    }

    public function testDeleteWithoutTenantDelegatesToInner(): void
    {
        $this->inner->expects($this->once())->method('delete')->with('foo')->willReturn(true);
        $this->assertTrue($this->adapter->delete('foo'));
    }

    public function testDeleteWithTenantAppliesSubNamespace(): void
    {
        $this->tenantContext->setTenant($this->tenant);
        $this->inner->expects($this->once())->method('withSubNamespace')->with('acme.')->willReturnSelf();
        $this->inner->expects($this->once())->method('delete')->with('foo')->willReturn(true);

        $this->assertTrue($this->adapter->delete('foo'));
    }

    public function testPruneDelegatesToInnerIgnoringTenant(): void
    {
        $this->tenantContext->setTenant($this->tenant);
        // prune is pool-wide — namespace is intentionally NOT applied (RESEARCH § 2.3)
        $this->inner->expects($this->never())->method('withSubNamespace');
        $this->inner->expects($this->once())->method('prune')->willReturn(true);

        $this->assertTrue($this->adapter->prune());
    }

    public function testResetDelegatesToInnerIgnoringTenant(): void
    {
        $this->tenantContext->setTenant($this->tenant);
        $this->inner->expects($this->never())->method('withSubNamespace');
        $this->inner->expects($this->once())->method('reset');

        $this->adapter->reset();
    }
}
