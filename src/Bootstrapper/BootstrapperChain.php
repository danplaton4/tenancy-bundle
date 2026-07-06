<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\Health\BootstrapperHealthResult;
use Tenancy\Bundle\Health\HealthCheckBootstrapperInterface;
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

    /**
     * Runs a read-only connectivity probe on every bootstrapper that opts in via
     * {@see HealthCheckBootstrapperInterface}. Non-implementing bootstrappers are
     * silently skipped.
     *
     * Exceptions thrown by individual probes are caught here and converted to
     * {@see BootstrapperHealthResult::fromException()} entries so a single failing
     * driver cannot abort the whole health check (T-33-PROP mitigation).
     *
     * NOTE: This method dispatches NO events. boot()/clear() are never called.
     *
     * @return BootstrapperHealthResult[]
     */
    public function healthCheck(TenantInterface $tenant): array
    {
        $results = [];

        foreach ($this->bootstrappers as $bootstrapper) {
            if (!$bootstrapper instanceof HealthCheckBootstrapperInterface) {
                continue;
            }

            try {
                $results[] = $bootstrapper->check($tenant);
            } catch (\Throwable $e) {
                $results[] = BootstrapperHealthResult::fromException($bootstrapper::class, $e);
            }
        }

        return $results;
    }
}
