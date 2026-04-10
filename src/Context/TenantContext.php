<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Context;

use Tenancy\Bundle\TenantInterface;

final class TenantContext
{
    private ?TenantInterface $currentTenant = null;

    public function setTenant(TenantInterface $tenant): void
    {
        $this->currentTenant = $tenant;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->currentTenant;
    }

    public function hasTenant(): bool
    {
        return null !== $this->currentTenant;
    }

    public function clear(): void
    {
        $this->currentTenant = null;
    }
}
