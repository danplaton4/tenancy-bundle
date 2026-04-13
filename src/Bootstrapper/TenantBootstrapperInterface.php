<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\TenantInterface;

interface TenantBootstrapperInterface
{
    public function boot(TenantInterface $tenant): void;

    public function clear(): void;
}
