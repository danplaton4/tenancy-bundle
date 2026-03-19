<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Driver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Driver\SharedDriver;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\TenantInterface;

final class SharedDriverTest extends TestCase
{
    private EntityManagerInterface $em;
    private FilterCollection $filterCollection;
    private TenantContext $tenantContext;
    private TenantInterface $tenant;
    private SharedDriver $driver;

    protected function setUp(): void
    {
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->filterCollection = $this->createMock(FilterCollection::class);
        $this->tenantContext    = new TenantContext();
        $this->tenant           = $this->createMock(TenantInterface::class);

        $this->em
            ->method('getFilters')
            ->willReturn($this->filterCollection);

        $this->driver = new SharedDriver($this->em, $this->tenantContext, true);
    }

    /**
     * Test 1: boot() calls setTenantContext on the filter instance retrieved
     * from EntityManager's FilterCollection.
     */
    public function testBootCallsSetTenantContextOnFilter(): void
    {
        $filter = $this->createMock(TenantAwareFilter::class);

        $this->filterCollection
            ->expects($this->once())
            ->method('getFilter')
            ->with('tenancy_aware')
            ->willReturn($filter);

        $filter
            ->expects($this->once())
            ->method('setTenantContext');

        $this->driver->boot($this->tenant);
    }

    /**
     * Test 2: boot() passes TenantContext and strictMode to setTenantContext.
     */
    public function testBootPassesTenantContextAndStrictModeToFilter(): void
    {
        $filter = $this->createMock(TenantAwareFilter::class);

        $this->filterCollection
            ->method('getFilter')
            ->with('tenancy_aware')
            ->willReturn($filter);

        $driver = new SharedDriver($this->em, $this->tenantContext, false);

        $filter
            ->expects($this->once())
            ->method('setTenantContext')
            ->with($this->tenantContext, false);

        $driver->boot($this->tenant);
    }

    /**
     * Test 3: clear() does not throw and does not interact with EM or filter.
     */
    public function testClearIsNoOpAndDoesNotThrow(): void
    {
        $this->em
            ->expects($this->never())
            ->method('getFilters');

        $this->filterCollection
            ->expects($this->never())
            ->method('getFilter');

        // Should not throw
        $this->driver->clear();

        $this->assertTrue(true); // explicit assertion to avoid risky-test
    }

    /**
     * Test 4: SharedDriver implements TenantDriverInterface.
     */
    public function testImplementsTenantDriverInterface(): void
    {
        $this->assertInstanceOf(TenantDriverInterface::class, $this->driver);
    }

    /**
     * Additional: SharedDriver also implements TenantBootstrapperInterface (via TenantDriverInterface).
     */
    public function testImplementsTenantBootstrapperInterface(): void
    {
        $this->assertInstanceOf(TenantBootstrapperInterface::class, $this->driver);
    }
}
