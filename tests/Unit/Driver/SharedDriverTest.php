<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Driver;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Driver\SharedDriver;
use Tenancy\Bundle\Driver\TenantDriverInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Spy that extends SQLFilter so it satisfies FilterCollection::getFilter()'s return type.
 *
 * TenantAwareFilter is final — PHPUnit cannot mock it.
 * This spy extends the abstract SQLFilter base class, passing a mock EM to the
 * final constructor, then records setTenantContext() call arguments for assertions.
 */
final class FilterSpy extends SQLFilter
{
    public ?TenantContext $capturedContext = null;
    public ?bool $capturedStrictMode = null;
    public int $callCount = 0;

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return '';
    }

    public function setTenantContext(TenantContext $context, bool $strictMode): void
    {
        $this->capturedContext = $context;
        $this->capturedStrictMode = $strictMode;
        ++$this->callCount;
    }
}

final class SharedDriverTest extends TestCase
{
    private EntityManagerInterface $em;
    private FilterCollection $filterCollection;
    private TenantContext $tenantContext;
    private TenantInterface $tenant;
    private SharedDriver $driver;
    private FilterSpy $filterSpy;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->filterCollection = $this->createMock(FilterCollection::class);
        $this->tenantContext = new TenantContext();
        $this->tenant = $this->createMock(TenantInterface::class);

        // FilterSpy extends SQLFilter — its constructor requires an EntityManagerInterface.
        // We pass the mock EM so the parent constructor is satisfied.
        $this->filterSpy = new FilterSpy($this->em);

        $this->em
            ->method('getFilters')
            ->willReturn($this->filterCollection);

        $this->filterCollection
            ->method('getFilter')
            ->with('tenancy_aware')
            ->willReturn($this->filterSpy);

        $this->driver = new SharedDriver($this->em, $this->tenantContext, true);
    }

    /**
     * Test 1: boot() calls setTenantContext on the filter instance retrieved
     * from EntityManager's FilterCollection.
     */
    public function testBootCallsSetTenantContextOnFilter(): void
    {
        $this->driver->boot($this->tenant);

        $this->assertSame(1, $this->filterSpy->callCount, 'setTenantContext should be called exactly once');
    }

    /**
     * Test 2: boot() passes TenantContext and strictMode to setTenantContext.
     */
    public function testBootPassesTenantContextAndStrictModeToFilter(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $filterCollection = $this->createMock(FilterCollection::class);
        $spy = new FilterSpy($em);

        $em->method('getFilters')->willReturn($filterCollection);
        $filterCollection->method('getFilter')->with('tenancy_aware')->willReturn($spy);

        $driver = new SharedDriver($em, $this->tenantContext, false);
        $driver->boot($this->tenant);

        $this->assertSame($this->tenantContext, $spy->capturedContext);
        $this->assertFalse($spy->capturedStrictMode);
    }

    /**
     * Test 3: clear() does not throw and does not interact with EM or filter.
     */
    public function testClearIsNoOpAndDoesNotThrow(): void
    {
        $this->em
            ->expects($this->never())
            ->method('getFilters');

        // Should not throw
        $this->driver->clear();

        $this->assertSame(0, $this->filterSpy->callCount, 'clear() must not call setTenantContext');
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
