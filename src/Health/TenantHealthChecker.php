<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

/**
 * Core health-check orchestrator for a single tenant.
 *
 * Enforces the set→probe→clear-in-finally invariant (HEALTH-03):
 *   1. Sets TenantContext manually (NOT via the request orchestrator).
 *   2. Invokes BootstrapperChain::healthCheck() (never boot()).
 *   3. Clears TenantContext in a finally block — even on exception.
 *
 * After checkOne() returns, TenantContext::hasTenant() is ALWAYS false.
 * This guarantee is load-bearing in long-running runtimes (FrankenPHP, Swoole)
 * where a leaked context would contaminate the next request (T-33-03 mitigation).
 *
 * @see BootstrapperChain::healthCheck()   The probe dispatch method (never boot())
 * @see HealthCheckBootstrapperInterface   The opt-in probe interface
 */
final class TenantHealthChecker implements TenantHealthCheckerInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
    ) {
    }

    /**
     * Runs a health probe for a single tenant and returns an aggregate report.
     *
     * The probe lifecycle is:
     *   setTenant($tenant) → healthCheck($tenant) → clear() [in finally]
     *
     * boot() is NEVER called. No events are dispatched.
     * TenantContext is ALWAYS cleared, even when healthCheck() throws.
     */
    public function checkOne(TenantInterface $tenant): TenantHealthReport
    {
        $this->tenantContext->setTenant($tenant);

        try {
            $results = $this->bootstrapperChain->healthCheck($tenant);

            return TenantHealthReport::fromResults($tenant->getSlug(), $results);
        } catch (\Throwable $e) {
            return TenantHealthReport::fromException($tenant->getSlug(), $e);
        } finally {
            // ALWAYS runs — the probe-safety invariant (HEALTH-03, T-33-03).
            $this->tenantContext->clear();
        }
    }
}
