<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\TenantInterface;

final class BootstrapperChain
{
    /** @var TenantBootstrapperInterface[] */
    private array $bootstrappers = [];

    public function addBootstrapper(TenantBootstrapperInterface $bootstrapper): void
    {
        $this->bootstrappers[] = $bootstrapper;
    }

    public function boot(TenantInterface $tenant): void
    {
        foreach ($this->bootstrappers as $bootstrapper) {
            $bootstrapper->boot($tenant);
        }
    }

    public function clear(): void
    {
        foreach (array_reverse($this->bootstrappers) as $bootstrapper) {
            $bootstrapper->clear();
        }
    }
}
