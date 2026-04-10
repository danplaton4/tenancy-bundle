<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Tenancy\Bundle\Attribute\TenantAware;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\TenantMissingException;

final class TenantAwareFilter extends SQLFilter
{
    private ?TenantContext $tenantContext = null;
    private bool $strictMode = true;

    public function setTenantContext(TenantContext $context, bool $strictMode): void
    {
        $this->tenantContext = $context;
        $this->strictMode = $strictMode;
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Safety net: if setTenantContext was never called (e.g. console command
        // or test context before SharedDriver::boot()), skip filtering
        if ($this->tenantContext === null) {
            return '';
        }

        $reflClass = $targetEntity->reflClass;
        if ($reflClass === null || empty($reflClass->getAttributes(TenantAware::class))) {
            return '';
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant === null) {
            if ($this->strictMode) {
                throw new TenantMissingException($targetEntity->getName());
            }
            return '';
        }

        return sprintf(
            "%s.tenant_id = '%s'",
            $targetTableAlias,
            addslashes($tenant->getSlug())
        );
    }
}
