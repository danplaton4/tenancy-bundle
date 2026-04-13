<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\TenantInterface;

final class BootstrapperChain
{
    /** @var TenantBootstrapperInterface[] */
    private array $bootstrappers = [];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function addBootstrapper(TenantBootstrapperInterface $bootstrapper): void
    {
        $this->bootstrappers[] = $bootstrapper;
    }

    public function boot(TenantInterface $tenant): void
    {
        $fqcns = [];

        foreach ($this->bootstrappers as $bootstrapper) {
            $bootstrapper->boot($tenant);
            $fqcns[] = $bootstrapper::class;
        }

        $this->eventDispatcher->dispatch(new TenantBootstrapped($tenant, $fqcns));
    }

    public function clear(): void
    {
        foreach (array_reverse($this->bootstrappers) as $bootstrapper) {
            $bootstrapper->clear();
        }
    }
}
