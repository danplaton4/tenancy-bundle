<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Cache;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Cache\TenantAwareTagAwareCacheAdapter;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

final class TenantAwareTagAwareCacheAdapterTest extends TestCase
{
    /** @var TagAwareAdapter&MockObject */
    private TagAwareAdapter $inner;
    private TenantContext $tenantContext;
    /** @var TenantInterface&MockObject */
    private TenantInterface $tenant;
    private TenantAwareTagAwareCacheAdapter $adapter;

    protected function setUp(): void
    {
        // TagAwareAdapter is the stock Symfony concrete class that implements
        // TagAwareAdapterInterface + TagAwareCacheInterface + NamespacedPoolInterface
        // + PruneableInterface + ResettableInterface. PHPUnit can't build an intersection
        // mock of TagAwareAdapterInterface&TagAwareCacheInterface because both declare
        // the same invalidateTags() signature — mocking the concrete class sidesteps that.
        /** @var TagAwareAdapter&MockObject $inner */
        $inner = $this->getMockBuilder(TagAwareAdapter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->inner = $inner;

        $this->tenantContext = new TenantContext();
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->tenant->method('getSlug')->willReturn('acme');

        $this->adapter = new TenantAwareTagAwareCacheAdapter($this->inner, $this->tenantContext);
    }

    public function testExtendsBaseAndImplementsTagAwareContracts(): void
    {
        $reflection = new \ReflectionClass(TenantAwareTagAwareCacheAdapter::class);
        $this->assertSame(TenantAwareCacheAdapter::class, false !== $reflection->getParentClass() ? $reflection->getParentClass()->getName() : null);
        $this->assertContains(TagAwareAdapterInterface::class, $reflection->getInterfaceNames());
        $this->assertContains(TagAwareCacheInterface::class, $reflection->getInterfaceNames());
    }

    public function testInvalidateTagsWithoutTenantDelegatesToInner(): void
    {
        $this->inner->expects($this->never())->method('withSubNamespace');
        $this->inner->expects($this->once())->method('invalidateTags')->with(['foo', 'bar'])->willReturn(true);

        $this->assertTrue($this->adapter->invalidateTags(['foo', 'bar']));
    }

    public function testInvalidateTagsWithTenantAppliesSubNamespace(): void
    {
        $this->tenantContext->setTenant($this->tenant);
        $this->inner->expects($this->once())->method('withSubNamespace')->with('acme.')->willReturnSelf();
        $this->inner->expects($this->once())->method('invalidateTags')->with(['foo'])->willReturn(true);

        $this->assertTrue($this->adapter->invalidateTags(['foo']));
    }
}
