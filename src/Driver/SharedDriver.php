<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Driver;

use Doctrine\ORM\EntityManagerInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Filter\TenantAwareFilter;
use Tenancy\Bundle\TenantInterface;

/**
 * Shared-DB isolation driver. Injects TenantContext into the always-enabled
 * TenantAwareFilter so that addFilterConstraint() reads live tenant state.
 *
 * boot(): re-injects TenantContext into the filter as a safety measure
 *         (handles the case where disable()/enable() recreated the filter instance).
 * clear(): no-op — TenantContext::clear() is called by BootstrapperChain,
 *          and the filter reads hasTenant() live on each query.
 */
final class SharedDriver implements TenantDriverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly bool $strictMode,
    ) {
    }

    public function boot(TenantInterface $tenant): void
    {
        /** @var TenantAwareFilter $filter */
        $filter = $this->em->getFilters()->getFilter('tenancy_aware');
        $filter->setTenantContext($this->tenantContext, $this->strictMode);
    }

    public function clear(): void
    {
        // No action needed. TenantContext::clear() is called by BootstrapperChain
        // before this method runs. The filter reads TenantContext::hasTenant()
        // live at query time, so it will correctly throw or return '' on next query.
    }
}
