<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Health;

use Tenancy\Bundle\TenantInterface;

/**
 * Contract for the tenant health-check orchestrator.
 *
 * Implemented by {@see TenantHealthChecker}. Exists as a separate interface
 * so that HTTP controllers and CLI commands can declare a typed dependency
 * that PHPUnit can double in unit tests (TenantHealthChecker is final).
 *
 * @see TenantHealthChecker  The concrete implementation
 */
interface TenantHealthCheckerInterface
{
    /**
     * Runs a health probe for a single tenant and returns an aggregate report.
     *
     * The implementation MUST follow the set→probe→clear-in-finally invariant:
     * after this method returns, TenantContext::hasTenant() MUST be false.
     *
     * boot() MUST NOT be called during the probe lifecycle.
     */
    public function checkOne(TenantInterface $tenant): TenantHealthReport;
}
